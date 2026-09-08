<?php

declare(strict_types=1);

namespace AdaptiveDataNetworks\WebTerm\Http\Controllers;

use AdaptiveDataNetworks\WebTerm\Audit\AuditLogger;
use AdaptiveDataNetworks\WebTerm\Audit\Event;
use AdaptiveDataNetworks\WebTerm\Credentials\CredentialScope;
use AdaptiveDataNetworks\WebTerm\Gateway\GatewayClient;
use AdaptiveDataNetworks\WebTerm\Librenms\DeviceNames;
use AdaptiveDataNetworks\WebTerm\Models\Ability;
use AdaptiveDataNetworks\WebTerm\Models\AuditEntry;
use AdaptiveDataNetworks\WebTerm\Models\Credential;
use AdaptiveDataNetworks\WebTerm\Models\Grant;
use AdaptiveDataNetworks\WebTerm\Models\HostKey;
use AdaptiveDataNetworks\WebTerm\Models\Session;
use AdaptiveDataNetworks\WebTerm\Models\Target;
use AdaptiveDataNetworks\WebTerm\WebTermServiceProvider;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Throwable;

/**
 * The admin console.
 *
 * Deliberately narrow. Credential *entry* is not here and is not coming: a
 * credential should require shell access on the LibreNMS host, not merely an
 * admin web session, because a public-facing PHP application's compromise is
 * the largest residual risk in this design. What the console does show is which
 * credentials exist and what they apply to, which is not a secret.
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
    private const TABS = ['targets', 'access', 'hostkeys', 'sessions', 'audit'];

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
            return $this->back('targets', 'No target exists for that device. Create it with webterm:target:enable.');
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
            return $this->back('access', 'That grant no longer exists.');
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
            return $this->back('access', 'Refusing to remove your own admin ability -- use the CLI if you mean it.');
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
            return $this->back('sessions', 'That session is already gone.');
        }

        // The same call webterm:sessions --kill makes, rather than a second
        // implementation of the same thing. The gateway client carries its own
        // timeout, so a blackholed gateway fails this request instead of
        // holding a php-fpm worker open.
        try {
            (new GatewayClient)->killSession($session->session_id, 'terminated from the console');
        } catch (Throwable $e) {
            return $this->back('sessions', 'Could not reach the gateway: '.$e->getMessage());
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

    private function back(string $tab, string $message): RedirectResponse
    {
        return redirect()
            ->to(url('plugin/webterm/admin').'?tab='.$tab)
            ->with('webterm_status', $message);
    }
}
