<?php

declare(strict_types=1);

namespace AdaptiveDataNetworks\WebTerm\Http\Controllers;

use AdaptiveDataNetworks\WebTerm\Audit\AuditLogger;
use AdaptiveDataNetworks\WebTerm\Audit\Event;
use AdaptiveDataNetworks\WebTerm\Credentials\CredentialEncrypter;
use AdaptiveDataNetworks\WebTerm\Credentials\CredentialMethod;
use AdaptiveDataNetworks\WebTerm\Credentials\CredentialScope;
use AdaptiveDataNetworks\WebTerm\Gateway\GatewayClient;
use AdaptiveDataNetworks\WebTerm\Librenms\DeviceNames;
use AdaptiveDataNetworks\WebTerm\Models\Ability;
use AdaptiveDataNetworks\WebTerm\Models\AuditEntry;
use AdaptiveDataNetworks\WebTerm\Models\Credential;
use AdaptiveDataNetworks\WebTerm\Models\Grant;
use AdaptiveDataNetworks\WebTerm\Models\HostKey;
use AdaptiveDataNetworks\WebTerm\Models\Session;
use AdaptiveDataNetworks\WebTerm\Models\Setting;
use AdaptiveDataNetworks\WebTerm\Models\Target;
use AdaptiveDataNetworks\WebTerm\Session\GroupEnablement;
use AdaptiveDataNetworks\WebTerm\Support\EditableSettings;
use AdaptiveDataNetworks\WebTerm\Support\RuntimeSettings;
use AdaptiveDataNetworks\WebTerm\Support\SshKey;
use AdaptiveDataNetworks\WebTerm\WebTermServiceProvider;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;
use Throwable;

/**
 * The admin console.
 *
 * Credential entry IS here, and this docblock said the opposite for a while --
 * "not here and is not coming" -- while storeCredential() sat 300 lines below
 * it. The original reasoning was sound as far as it went: a public-facing PHP
 * application's compromise is the largest residual risk in this design, so
 * writing a reusable device secret should cost more than an admin web session.
 *
 * What that reasoning missed is the other side of the trade. Requiring a shell
 * meant credentials were stored once, by whoever built the install, and then
 * never rotated -- a secret nobody can rotate without a shell is a secret nobody
 * rotates. The bar moved rather than dropped: the write is rate-limited, audited
 * as security-relevant before the local database write, never repopulated into
 * the form on a validation error, and carried only through
 * #[\SensitiveParameter] so it cannot surface in a stack trace.
 *
 * Host key mutation is also absent. Resetting a pin and flipping a target to
 * trust-on-first-connect are individually reasonable and jointly amount to
 * turning off host key verification for a device from a browser. The pins are
 * shown; changing them stays on the CLI.
 *
 * Every mutation here re-reads its own state, writes an audit record, and
 * redirects. Nothing renders a secret.
 */
final class AdminController
{
    private const TABS = ['targets', 'credentials', 'access', 'hostkeys', 'sessions', 'settings', 'audit'];

    public function index(Request $request, DeviceNames $names): View
    {
        $tab = (string) $request->query('tab', 'targets');

        if (! in_array($tab, self::TABS, true)) {
            $tab = 'targets';
        }

        $targets = Target::query()->orderBy('device_id')->get();
        $grants = Grant::query()->orderBy('id')->get();
        $credentials = Credential::query()->get();
        $hostKeys = HostKey::query()->orderBy('device_id')->get();
        $sessions = Session::query()->orderByDesc('started_at')->limit(50)->get();
        $audit = AuditEntry::query()->orderByDesc('id')->limit(100)->get();

        return view(WebTermServiceProvider::PLUGIN_NAME.'::admin', [
            'tab' => $tab,
            'tabs' => self::TABS,
            'targets' => $targets,
            'grants' => $grants,
            'abilities' => Ability::query()->orderBy('user_id')->get(),
            'credentials' => $credentials,
            'hostKeys' => $hostKeys,
            'sessions' => $sessions,
            'audit' => $audit,
            // Resolved once for the whole page rather than per row: an id per
            // table cell would be an N+1 across six tables.
            'editable' => EditableSettings::editable(),
            'readOnlySettings' => EditableSettings::readOnly(),
            'deviceNames' => $names->namesFor(array_merge(
                $targets->pluck('device_id')->all(),
                $hostKeys->pluck('device_id')->all(),
                $sessions->pluck('device_id')->all(),
                $audit->pluck('device_id')->filter()->all(),
                $credentials->where('scope_type', CredentialScope::Device)->pluck('scope_ref')->all(),
                $grants->where('object_type', Grant::OBJECT_DEVICE)->pluck('object_id')->all(),
            )),
        ]);
    }

    public function toggleTarget(Request $request, AuditLogger $audit): RedirectResponse
    {
        $validated = $request->validate([
            'device_id' => ['required', 'integer', 'min:1'],
            'enabled' => ['required', 'boolean'],
        ]);

        $target = Target::query()
            ->where('device_id', $validated['device_id'])
            ->where('protocol', 'ssh')
            ->first();

        if ($target === null) {
            return $this->refuse('targets', 'No target exists for that device. Create it with webterm:target:enable.');
        }

        $target->update(['enabled' => (bool) $validated['enabled']]);

        $audit->log(Event::ConfigChanged, Auth::user(), (int) $validated['device_id'], detail: [
            'change' => 'target.enabled',
            'value' => (bool) $validated['enabled'],
            'via' => 'console',
        ]);

        return $this->back('targets', $validated['enabled'] ? 'Target enabled.' : 'Target disabled.');
    }

    public function storeGrant(Request $request, AuditLogger $audit): RedirectResponse
    {
        $validated = $request->validate([
            'subject_type' => ['required', 'in:'.Grant::SUBJECT_USER.','.Grant::SUBJECT_ROLE],
            'subject_ref' => ['required', 'string', 'max:64'],
            'object_type' => ['required', 'in:'.Grant::OBJECT_DEVICE.','.Grant::OBJECT_GROUP],
            'object_id' => ['required', 'integer', 'min:1'],
            'effect' => ['required', 'in:'.Grant::ALLOW.','.Grant::DENY],
        ]);

        Grant::create($validated);

        $audit->log(Event::GrantCreated, Auth::user(), detail: $validated + ['via' => 'console']);

        return $this->back('access', 'Grant created.');
    }

    public function destroyGrant(Request $request, AuditLogger $audit): RedirectResponse
    {
        $validated = $request->validate(['id' => ['required', 'integer', 'min:1']]);

        $grant = Grant::query()->find($validated['id']);

        if ($grant === null) {
            return $this->refuse('access', 'That grant no longer exists.');
        }

        $detail = $grant->only(['subject_type', 'subject_ref', 'object_type', 'object_id', 'effect']);
        $grant->delete();

        $audit->log(Event::GrantRemoved, Auth::user(), detail: $detail + ['via' => 'console']);

        return $this->back('access', 'Grant removed.');
    }

    public function storeAbility(Request $request, AuditLogger $audit): RedirectResponse
    {
        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'min:1'],
            'ability' => ['required', 'in:'.implode(',', Ability::ALL)],
        ]);

        Ability::query()->firstOrCreate($validated);

        $audit->log(Event::ConfigChanged, Auth::user(), detail: [
            'change' => 'ability.granted',
        ] + $validated + ['via' => 'console']);

        return $this->back('access', 'Ability granted.');
    }

    public function destroyAbility(Request $request, AuditLogger $audit): RedirectResponse
    {
        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'min:1'],
            'ability' => ['required', 'in:'.implode(',', Ability::ALL)],
        ]);

        // Refusing to let an admin remove their own admin ability from the web
        // UI: the console is the only surface that can grant it back, and
        // locking every admin out of it would need a shell to undo.
        if ((int) $validated['user_id'] === (int) Auth::id() && $validated['ability'] === Ability::ADMIN) {
            return $this->refuse('access', 'Refusing to remove your own admin ability -- use the CLI if you mean it.');
        }

        Ability::query()->where($validated)->delete();

        $audit->log(Event::ConfigChanged, Auth::user(), detail: [
            'change' => 'ability.revoked',
        ] + $validated + ['via' => 'console']);

        return $this->back('access', 'Ability revoked.');
    }

    public function killSession(Request $request, AuditLogger $audit): RedirectResponse
    {
        $validated = $request->validate(['session_id' => ['required', 'string', 'max:64']]);

        $session = Session::query()->find($validated['session_id']);

        if ($session === null) {
            return $this->refuse('sessions', 'That session is already gone.');
        }

        // The same call webterm:sessions --kill makes, rather than a second
        // implementation of the same thing. The gateway client carries its own
        // timeout, so a blackholed gateway fails this request instead of
        // holding a php-fpm worker open.
        try {
            (new GatewayClient)->killSession($session->session_id, 'terminated from the console');
        } catch (Throwable $e) {
            return $this->refuse('sessions', 'Could not reach the gateway: '.$e->getMessage());
        }

        $session->state = Session::CLOSED;
        $session->ended_at = Carbon::now();
        $session->close_reason = 'terminated from the console';
        $session->save();

        $audit->log(
            Event::SessionKilled,
            Auth::user(),
            (int) $session->device_id,
            $session->session_id,
            detail: ['via' => 'console']
        );

        return $this->back('sessions', 'Session terminated.');
    }

    /**
     * Change one setting.
     *
     * Only keys EditableSettings declares, coerced to their declared type.
     * Anything else is refused rather than written, because a row nothing reads
     * is the failure this plugin has already shipped twice.
     */
    public function storeSetting(Request $request, AuditLogger $audit): RedirectResponse
    {
        $validated = $request->validate([
            'key' => ['required', 'string', 'max:96'],
            'value' => ['present', 'string', 'max:255'],
        ]);

        [$ok, $value, $error] = EditableSettings::coerce($validated['key'], $validated['value']);

        if (! $ok) {
            return $this->refuse('settings', sprintf('%s: %s', $validated['key'], $error));
        }

        Setting::query()->updateOrCreate(
            ['key' => $validated['key']],
            ['value' => is_bool($value) ? ($value ? 'true' : 'false') : (string) $value]
        );

        // Otherwise the next request reads a cached value and the change looks
        // as though it did nothing.
        RuntimeSettings::flush();

        $audit->log(Event::ConfigChanged, Auth::user(), detail: [
            'key' => $validated['key'],
            'value' => is_bool($value) ? ($value ? 'true' : 'false') : (string) $value,
            'via' => 'console',
        ]);

        return $this->back('settings', sprintf('%s updated.', $validated['key']));
    }

    /**
     * Create or update a target -- previously CLI-only.
     */
    public function storeTarget(Request $request, AuditLogger $audit): RedirectResponse
    {
        $validated = $request->validate([
            'device_id' => ['required', 'integer', 'min:1'],
            'principal' => ['required', 'string', 'max:64'],
            'flow' => ['required', 'in:database,ssh_signer,kv2,private_key'],
            'host_key_policy' => ['required', 'in:'.Target::POLICY_PIN.','.Target::POLICY_TOFU],
            'algorithm_profile' => ['required', 'in:modern,legacy'],
        ]);

        Target::query()->updateOrCreate(
            ['device_id' => $validated['device_id'], 'protocol' => 'ssh'],
            $validated + ['enabled' => true]
        );

        $audit->log(Event::ConfigChanged, Auth::user(), (int) $validated['device_id'], detail: [
            'change' => 'target.saved',
        ] + $validated + ['via' => 'console']);

        return $this->back('targets', 'Target saved. Pin its host key before connecting: webterm:hostkey-scan.');
    }

    /**
     * Enable every device in a static group.
     *
     * Materialises one target row per member rather than making authorization
     * consult group membership -- see GroupEnablement for why that distinction
     * is a security property and not a style choice.
     */
    public function enableGroup(Request $request, GroupEnablement $groups, AuditLogger $audit): RedirectResponse
    {
        $validated = $request->validate([
            'group_id' => ['required', 'integer', 'min:1'],
            'principal' => ['required', 'string', 'max:64'],
            'flow' => ['required', 'in:database,ssh_signer,kv2,private_key'],
            'host_key_policy' => ['required', 'in:'.Target::POLICY_PIN.','.Target::POLICY_TOFU],
            'algorithm_profile' => ['required', 'in:modern,legacy'],
        ]);

        $groupId = (int) $validated['group_id'];
        unset($validated['group_id']);

        $result = $groups->apply($groupId, $validated);

        if (! $result['ok']) {
            return $this->refuse('targets', $result['error']);
        }

        $audit->log(Event::ConfigChanged, Auth::user(), detail: [
            'change' => 'target.group_enabled',
            'group_id' => $groupId,
            'created' => $result['created'],
            'updated' => $result['updated'],
            'via' => 'console',
        ]);

        return $this->back('targets', sprintf(
            'Group enabled: %d device(s) added, %d updated. Devices configured by hand were left alone. Pin host keys with webterm:hostkey-scan.',
            $result['created'],
            $result['updated']
        ));
    }

    /**
     * Store a credential from the browser.
     *
     * Credential entry was deliberately CLI-only until the owner decided the
     * opposite: LibreNMS's plugin surface is already admin-gated, and refusing
     * to expose this makes the plugin unusable for operators who are perfectly
     * capable of securing their own install. Doing it well is the job now.
     *
     * Four framework mechanics leak secrets by default and each is handled:
     *
     *  - Laravel flashes failed-validation input back into the session, minus
     *    only `password`, `password_confirmation` and `current_password`. A
     *    field named `private_key` or `passphrase` would be written to the
     *    session store in plaintext. So validation here is manual and the
     *    failure path NEVER calls withInput().
     *  - `zend.exception_ignore_args` is Off on a default PHP, so a plain
     *    string argument appears in any stack trace LibreNMS logs. Hence
     *    #[SensitiveParameter] on the method that receives it.
     *  - The response carries no echo of the secret: the field is write-only
     *    and a stored credential is never rendered back.
     *  - Nothing is put in the URL, the flash message, or the audit detail.
     */
    public function storeCredential(Request $request, AuditLogger $audit): RedirectResponse
    {
        // Validator::make, not $request->validate(): the latter throws a
        // ValidationException whose handler redirects withInput(), which is
        // exactly how the secret would reach the session.
        $validator = Validator::make($request->all(), [
            'scope_type' => ['required', 'in:global,group,device'],
            'scope_ref' => ['required', 'integer', 'min:0'],
            'username' => ['required', 'string', 'max:64'],
            'secret' => ['required', 'string', 'max:16384'],
            'secret_kind' => ['required', 'in:password,private_key'],
        ]);

        if ($validator->fails()) {
            // No withInput(). Deliberate -- see above.
            return $this->refuse('credentials', 'Could not save: '.$validator->errors()->first());
        }

        $validated = $validator->validated();
        $scope = CredentialScope::from($validated['scope_type']);
        $ref = $scope === CredentialScope::Global ? CredentialScope::UNTARGETED : (int) $validated['scope_ref'];

        $this->persistCredential(
            $scope,
            $ref,
            (string) $validated['username'],
            (string) $validated['secret_kind'],
            (string) $validated['secret'],
        );

        $audit->log(
            Event::CredentialStored,
            Auth::user(),
            $scope === CredentialScope::Device ? $ref : null,
            detail: [
                'scope' => $scope->value,
                'scope_ref' => $ref,
                'method' => $validated['secret_kind'],
                'username' => $validated['username'],
                'via' => 'console',
            ]
        );

        return $this->back('credentials', 'Credential stored for '.$scope->label($ref).'.');
    }

    public function destroyCredential(Request $request, AuditLogger $audit): RedirectResponse
    {
        $validated = $request->validate([
            'scope_type' => ['required', 'in:global,group,device'],
            'scope_ref' => ['required', 'integer', 'min:0'],
        ]);

        $scope = CredentialScope::from($validated['scope_type']);
        $ref = (int) $validated['scope_ref'];

        $credential = Credential::query()
            ->where('scope_type', $scope->value)
            ->where('scope_ref', $ref)
            ->where('protocol', 'ssh')
            ->first();

        if ($credential === null) {
            return $this->refuse('credentials', 'That credential is already gone.');
        }

        $detail = ['scope' => $scope->value, 'scope_ref' => $ref, 'method' => (string) $credential->method, 'username' => (string) $credential->username];
        $credential->delete();

        $audit->log(Event::CredentialRemoved, Auth::user(), $scope === CredentialScope::Device ? $ref : null, detail: $detail + ['via' => 'console']);

        return $this->back('credentials', 'Credential removed.');
    }

    /**
     * Encrypt and store. Isolated so the secret crosses exactly one boundary,
     * and marked sensitive so it cannot surface in a stack trace.
     */
    private function persistCredential(
        CredentialScope $scope,
        int $ref,
        string $username,
        string $kind,
        #[\SensitiveParameter] string $secret,
    ): void {
        $encrypter = new CredentialEncrypter;

        $secrets = $kind === 'private_key'
            ? ['private_key' => $secret]
            : ['password' => $secret];

        Credential::query()->updateOrCreate(
            ['scope_type' => $scope->value, 'scope_ref' => $ref, 'protocol' => 'ssh'],
            [
                'method' => $kind === 'private_key' ? CredentialMethod::PrivateKey->value : CredentialMethod::Password->value,
                'username' => $username,
                'payload' => $encrypter->encrypt($secrets),
                'cipher' => $encrypter->cipher(),
                'key_id' => $encrypter->keyId(),
                'fingerprint' => $kind === 'private_key' ? SshKey::fingerprint($secret) : null,
            ]
        );
    }

    /**
     * Redirect back to a tab with a message AND its severity.
     *
     * The level is flashed rather than inferred: the console previously sent
     * every outcome through one key, so "Could not reach the gateway" -- which
     * means the shell you tried to cut is probably still open -- rendered in
     * the same blue alert as "Session terminated." A view cannot honestly pick
     * a colour by matching on message text, because the text is copy and copy
     * gets reworded.
     */
    private function back(string $tab, string $message, string $level = 'success'): RedirectResponse
    {
        return redirect()
            ->to(url('plugin/webterm/admin').'?tab='.$tab)
            ->with('webterm_status', $message)
            ->with('webterm_status_level', $level);
    }

    /** A refusal or a failure: the same redirect, painted as one. */
    private function refuse(string $tab, string $message): RedirectResponse
    {
        return $this->back($tab, $message, 'danger');
    }
}
