<?php

declare(strict_types=1);

namespace AdaptiveDataNetworks\WebTerm\Session;

use AdaptiveDataNetworks\WebTerm\Audit\AuditLogger;
use AdaptiveDataNetworks\WebTerm\Audit\Event;
use AdaptiveDataNetworks\WebTerm\Authorization\Contracts\GroupSource;
use AdaptiveDataNetworks\WebTerm\Authorization\ReasonCode;
use AdaptiveDataNetworks\WebTerm\Authorization\ShellAuthorizer;
use AdaptiveDataNetworks\WebTerm\Credentials\CredentialManager;
use AdaptiveDataNetworks\WebTerm\Credentials\CredentialMethod;
use AdaptiveDataNetworks\WebTerm\Credentials\CredentialRequest;
use AdaptiveDataNetworks\WebTerm\Credentials\Exceptions\CredentialException;
use AdaptiveDataNetworks\WebTerm\Gateway\GatewayClient;
use AdaptiveDataNetworks\WebTerm\Gateway\GatewayException;
use AdaptiveDataNetworks\WebTerm\HostKeys\HostKeyManager;
use AdaptiveDataNetworks\WebTerm\Librenms\DeviceGroups;
use AdaptiveDataNetworks\WebTerm\Librenms\DeviceTarget;
use AdaptiveDataNetworks\WebTerm\Models\HostKey;
use AdaptiveDataNetworks\WebTerm\Models\Session as SessionModel;
use AdaptiveDataNetworks\WebTerm\Models\Target;
use AdaptiveDataNetworks\WebTerm\Models\Ticket;
use AdaptiveDataNetworks\WebTerm\Protocol;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Runs the three-phase handoff.
 *
 * Order is the security design, not an implementation detail:
 *
 *   1. authorise             -- refuse before anything else happens
 *   2. create pending session -- gateway returns a ticket and, for the
 *                                certificate flow, a freshly generated public
 *                                key whose private half never leaves it
 *   3. resolve the credential -- only now, after a real user really clicked
 *   4. push it over loopback  -- the one message on any wire carrying a secret
 *
 * Resolving the credential last is what makes browsing device pages free of
 * consequence: opening five device pages mints nothing.
 */
final class SessionMinter
{
    public function __construct(
        private readonly ShellAuthorizer $authorizer = new ShellAuthorizer,
        private readonly DeviceTarget $targets = new DeviceTarget,
        private readonly ?GatewayClient $gateway = null,
        private readonly ?CredentialManager $credentials = null,
        private readonly AuditLogger $audit = new AuditLogger,
        private readonly ?HostKeyManager $hostKeys = null,
        private readonly GroupSource $groups = new DeviceGroups,
    ) {}

    /**
     * @param  object  $device  A LibreNMS App\Models\Device.
     *
     * @throws MintException when authorization or credential resolution refuses
     * @throws GatewayException when the gateway is unreachable or mismatched
     */
    public function mint(Authenticatable $user, object $device): MintResult
    {
        $deviceId = (int) ($device->device_id ?? 0);

        $decision = $this->authorizer->admit($user, $device);
        if (! $decision->allowed) {
            $this->audit->log(
                Event::SessionDenied,
                $user,
                $deviceId,
                reasonCode: $decision->reason->value,
            );

            throw new MintException($decision->message(), $decision->reason);
        }

        $target = Target::query()
            ->where('device_id', $deviceId)
            ->where('protocol', 'ssh')
            ->firstOrFail();

        $dial = $this->targets->resolve($device);
        if ($dial === null) {
            throw new MintException('This device has no usable address.', ReasonCode::TargetUnresolvable);
        }

        $sessionId = strtoupper((string) Str::ulid());
        $method = $this->methodFor($target);
        $gateway = $this->gateway ?? new GatewayClient;

        // Trust-on-first-use records the pin BEFORE connecting, so the session
        // itself runs against a pinned key like any other. Refused outright for
        // reusable secrets: TOFU means handing the credential to whatever
        // answers, and a password given to an impostor is a lasting compromise
        // where a 30-minute certificate is not.
        ($this->hostKeys ?? new HostKeyManager($gateway))
            ->maybePinOnFirstConnect($target, $dial->ip, $dial->port, $method);

        // Phase 1.
        $created = $gateway->createSession([
            'protocol' => Protocol::VERSION,
            'session_id' => $sessionId,
            'target' => [
                'ip' => $dial->ip,
                'port' => $dial->port,
                'hostname_label' => (string) ($device->hostname ?? ''),
                'host_key_policy' => $target->host_key_policy,
                'known_hosts' => $this->knownHostsFor($deviceId),
                'algorithm_profile' => $target->algorithm_profile ?? 'modern',
            ],
            'auth' => [
                'method' => $method->value,
                'username' => (string) $target->principal,
                'key_algorithm' => 'ed25519',
            ],
            'limits' => [
                'idle_timeout' => $decision->limits->idleTimeout,
                'max_duration' => $decision->limits->maxDuration,
                'warn_at' => (array) config('webterm.session.warn_at', [300, 60]),
            ],
        ]);

        // Phases 2 and 3.
        try {
            $credential = ($this->credentials ?? app(CredentialManager::class))
                ->driver()
                ->resolve(new CredentialRequest(
                    $deviceId,
                    (string) $target->principal,
                    $user,
                    $created['public_key'] ?? null,
                    'ssh',
                    // Group membership is resolved here, where the Device model
                    // is in hand, so that a group-scoped credential can apply.
                    $this->groups->staticGroupIdsFor($device),
                ));

            $gateway->supplyCredential($sessionId, array_merge(
                ['method' => $credential->method->value],
                $credential->reveal(),
            ));

            $credential->consume();
        } catch (CredentialException $e) {
            $gateway->killSession($sessionId, 'credential resolution failed');

            $this->audit->log(
                Event::CredentialFailed,
                $user,
                $deviceId,
                $sessionId,
                detail: ['error' => $e->getMessage()],
            );

            throw new MintException($e->getMessage(), ReasonCode::NoPrincipal);
        }

        $this->record($sessionId, $user, $deviceId, $method, $target, $created, $dial->ip);

        $this->audit->log(Event::SessionRequested, $user, $deviceId, $sessionId);

        return new MintResult(
            $sessionId,
            (string) $created['ticket'],
            (string) config('webterm.gateway.ws_url', '/webterm/ws'),
            (string) config('webterm.gateway.ui_url', '/webterm/ui/'),
            (string) ($created['expires_at'] ?? ''),
        );
    }

    private function methodFor(Target $target): CredentialMethod
    {
        // Pinned per target, never inferred at connect time from devices.os --
        // a device silently switching to a reusable-secret flow would break the
        // security claim of a certificate-only deployment.
        return match ($target->flow) {
            'ssh_signer' => CredentialMethod::SignedCertificate,
            'private_key' => CredentialMethod::PrivateKey,
            default => CredentialMethod::Password,
        };
    }

    /** @return list<string> */
    private function knownHostsFor(int $deviceId): array
    {
        return HostKey::query()
            ->where('device_id', $deviceId)
            ->where('status', HostKey::PINNED)
            ->get()
            ->map(fn (HostKey $k): string => $k->toKnownHostsEntry())
            ->all();
    }

    /**
     * @param  array<string, mixed>  $created
     */
    private function record(
        string $sessionId,
        Authenticatable $user,
        int $deviceId,
        CredentialMethod $method,
        Target $target,
        array $created,
        string $ip,
    ): void {
        // Only the hash: a database read must not yield a usable ticket for an
        // in-flight session.
        Ticket::create([
            'session_id' => $sessionId,
            'ticket_hash' => Ticket::hash((string) $created['ticket']),
            'user_id' => $user->getAuthIdentifier(),
            'device_id' => $deviceId,
            'method' => $method->value,
            'principal' => $target->principal,
            'issued_at' => Carbon::now(),
            'expires_at' => Carbon::now()->addSeconds(Protocol::TICKET_TTL_SECONDS),
        ]);

        SessionModel::create([
            'session_id' => $sessionId,
            'user_id' => $user->getAuthIdentifier(),
            'device_id' => $deviceId,
            'gateway_instance_id' => $created['instance_id'] ?? null,
            'state' => SessionModel::PENDING,
            'method' => $method->value,
            'principal' => $target->principal,
            'target' => $ip,
            'started_at' => Carbon::now(),
            'last_seen_at' => Carbon::now(),
        ]);
    }
}
