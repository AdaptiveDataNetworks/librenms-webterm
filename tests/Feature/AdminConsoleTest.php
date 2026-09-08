<?php

declare(strict_types=1);

use AdaptiveDataNetworks\WebTerm\Audit\AuditLogger;
use AdaptiveDataNetworks\WebTerm\Audit\Event;
use AdaptiveDataNetworks\WebTerm\Hooks\Settings;
use AdaptiveDataNetworks\WebTerm\Models\Ability;
use AdaptiveDataNetworks\WebTerm\Models\AuditEntry;
use AdaptiveDataNetworks\WebTerm\Models\Credential;
use AdaptiveDataNetworks\WebTerm\Models\Grant;
use AdaptiveDataNetworks\WebTerm\Models\HostKey;
use AdaptiveDataNetworks\WebTerm\Models\Session;
use AdaptiveDataNetworks\WebTerm\Models\Target;
use AdaptiveDataNetworks\WebTerm\Tests\Fakes\FakePluginManager;
use AdaptiveDataNetworks\WebTerm\Tests\Support\FakeUser;
use AdaptiveDataNetworks\WebTerm\WebTermServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\ViewErrorBag;
use LibreNMS\Interfaces\Plugins\PluginManagerInterface;

uses(RefreshDatabase::class);

/**
 * The console is a write surface on a public-facing PHP application, so every
 * route is tested for who may reach it -- not just that it works.
 */
function bootConsole(bool $pluginEnabled = true): void
{
    config()->set('webterm.enabled', true);

    app()->instance(PluginManagerInterface::class, new FakePluginManager(enabled: $pluginEnabled));

    // Routes are registered from the provider's boot(), which under Testbench
    // returns early unless a plugin manager is bound.
    (new WebTermServiceProvider(app()))->boot();
}

function admin(int $id = 7): FakeUser
{
    Ability::create(['user_id' => $id, 'ability' => Ability::ADMIN]);

    return new FakeUser($id);
}

/** Every mutating route, so none can be added without an authorization test. */
function consoleMutations(): array
{
    return [
        ['plugin/webterm/admin/targets', ['device_id' => 1, 'enabled' => 1]],
        ['plugin/webterm/admin/grants', ['subject_type' => 'user', 'subject_ref' => '7', 'object_type' => 'device', 'object_id' => 1, 'effect' => 'allow']],
        ['plugin/webterm/admin/grants/delete', ['id' => 1]],
        ['plugin/webterm/admin/abilities', ['user_id' => 9, 'ability' => 'use']],
        ['plugin/webterm/admin/abilities/delete', ['user_id' => 9, 'ability' => 'use']],
        ['plugin/webterm/admin/sessions/kill', ['session_id' => 'nope']],
    ];
}

it('is not reachable by an authenticated user without the admin ability', function (): void {
    bootConsole();

    $this->actingAs(new FakeUser(7))
        ->get('plugin/webterm/admin')
        ->assertNotFound();
});

it('returns 404 rather than 403, so its existence is not confirmed', function (): void {
    bootConsole();

    // 403 would tell an unprivileged account that the console is there and that
    // they are merely not admin enough. 404 is what a nonexistent path returns.
    $response = $this->actingAs(new FakeUser(7))->get('plugin/webterm/admin');

    expect($response->getStatusCode())->toBe(404);
});

it('is reachable by a user holding the admin ability', function (): void {
    bootConsole();

    $this->actingAs(admin())
        ->get('plugin/webterm/admin')
        ->assertOk()
        ->assertSee('WebTerm');
});

it('refuses every mutation to a non-admin', function (): void {
    bootConsole();
    $user = new FakeUser(7);

    foreach (consoleMutations() as [$uri, $payload]) {
        $this->actingAs($user)
            ->post($uri, $payload)
            ->assertNotFound();
    }
});

it('disappears entirely once the plugin is disabled in LibreNMS', function (): void {
    // The routes survive a disable, because plugin:enable runs route:cache and
    // plugin:disable does not clear it. The per-request check is what actually
    // removes the surface.
    bootConsole(pluginEnabled: false);
    $admin = admin();

    $this->actingAs($admin)
        ->get('plugin/webterm/admin')
        ->assertNotFound();

    foreach (consoleMutations() as [$uri, $payload]) {
        $this->actingAs($admin)->post($uri, $payload)->assertNotFound();
    }
});

it('disappears when the kill switch is off', function (): void {
    bootConsole();
    config()->set('webterm.enabled', false);

    $this->actingAs(admin())
        ->get('plugin/webterm/admin')
        ->assertForbidden();
});

it('lets an admin create and remove a grant, and audits both', function (): void {
    bootConsole();
    $admin = admin();

    $this->actingAs($admin)->post('plugin/webterm/admin/grants', [
        'subject_type' => 'user',
        'subject_ref' => '9',
        'object_type' => 'device',
        'object_id' => 42,
        'effect' => 'allow',
    ])->assertRedirect();

    $grant = Grant::query()->firstOrFail();
    expect($grant->effect)->toBe('allow');

    $this->actingAs($admin)
        ->post('plugin/webterm/admin/grants/delete', ['id' => $grant->id])
        ->assertRedirect();

    expect(Grant::query()->count())->toBe(0);
    expect(AuditEntry::query()->whereIn('event', ['grant.created', 'grant.removed'])->count())->toBe(2);
});

it('rejects a grant with values outside the allowed vocabulary', function (): void {
    bootConsole();

    $this->actingAs(admin())->post('plugin/webterm/admin/grants', [
        'subject_type' => 'wizard',
        'subject_ref' => '9',
        'object_type' => 'device',
        'object_id' => 42,
        'effect' => 'allow',
    ])->assertSessionHasErrors('subject_type');

    expect(Grant::query()->count())->toBe(0);
});

it('will not let an admin revoke their own admin ability from the browser', function (): void {
    // The console is the only surface that can grant it back, so doing this
    // from the browser would need a shell to undo.
    bootConsole();
    $admin = admin();

    $this->actingAs($admin)
        ->post('plugin/webterm/admin/abilities/delete', ['user_id' => 7, 'ability' => 'admin'])
        ->assertRedirect();

    expect(Ability::query()->where('user_id', 7)->where('ability', 'admin')->exists())->toBeTrue();
});

it('never exposes a credential payload or secret in the console', function (): void {
    bootConsole();

    Credential::create([
        'scope_type' => 'global',
        'scope_ref' => 0,
        'protocol' => 'ssh',
        'method' => 'password',
        'username' => 'fleet-account',
        'payload' => 'SUPER-SECRET-PAYLOAD',
        'cipher' => 'aes-256-gcm',
        'key_id' => 'k',
    ]);

    $this->actingAs(admin())
        ->get('plugin/webterm/admin?tab=targets')
        ->assertOk()
        ->assertSee('fleet-account')
        ->assertDontSee('SUPER-SECRET-PAYLOAD');
});

it('offers the console link only to someone who can actually open it', function (): void {
    // The console answers 404 without WebTerm's own admin ability, which is
    // separate from the LibreNMS admin role. Linking to it unconditionally
    // hands a LibreNMS admin who lacks that ability a button to a dead end.
    bootConsole();

    $hook = new Settings;

    $this->actingAs(new FakeUser(7));
    expect($hook->handle('WebTerm', [])['webtermConsole'])->toBeFalse();

    $this->actingAs(admin(7));
    expect($hook->handle('WebTerm', [])['webtermConsole'])->toBeTrue();
});

it('tells the operator when the console is unreachable by everyone', function (): void {
    // A 404 is indistinguishable from a broken install in a browser. Doctor is
    // the one place that can say the console is merely ungranted.
    bootConsole();

    $this->artisan('webterm:doctor')
        ->expectsOutputToContain('nobody holds the admin ability');

    admin(7);

    $this->artisan('webterm:doctor')
        ->expectsOutputToContain('1 user(s) may open it');
});

/**
 * Every tab, with a row in every table.
 *
 * The console shipped with a fatal in the Access tab -- `__('device')` collides
 * with LibreNMS's lang/en/device.php and returns that whole file as an array,
 * which htmlspecialchars() rejects. Nothing caught it because every test until
 * now rendered only the default tab, on empty tables.
 */
it('renders every tab, with data in every table', function (string $tab): void {
    bootConsole();

    Target::create([
        'device_id' => 42, 'protocol' => 'ssh', 'enabled' => true, 'flow' => 'database',
        'host_key_policy' => Target::POLICY_PIN, 'principal' => 'netops',
    ]);
    Grant::create([
        'subject_type' => 'user', 'subject_ref' => '7',
        'object_type' => 'device', 'object_id' => 42, 'effect' => 'allow',
    ]);
    HostKey::create([
        'device_id' => 42, 'algorithm' => 'ssh-ed25519', 'public_key' => 'AAAAC3',
        'fingerprint' => 'SHA256:test', 'status' => HostKey::PINNED,
    ]);
    Credential::create([
        'scope_type' => 'global', 'scope_ref' => 0, 'protocol' => 'ssh', 'method' => 'password',
        'username' => 'fleet', 'payload' => 'x', 'cipher' => 'aes-256-gcm', 'key_id' => 'k',
    ]);
    Session::create([
        'session_id' => '01JQWERTYUIOPASDFGHJKLZXCV', 'user_id' => 7, 'device_id' => 42,
        'state' => 'active', 'method' => 'password',
    ]);
    app(AuditLogger::class)
        ->log(Event::SessionStarted, null, 42, '01JQWERTYUIOPASDFGHJKLZXCV');

    $this->actingAs(admin())
        ->get('plugin/webterm/admin?tab='.$tab)
        ->assertOk()
        ->assertDontSee('Whoops');
})->with(['targets', 'access', 'hostkeys', 'sessions', 'audit']);

it('shows linked device names, not raw ids', function (): void {
    // "Nobody knows what an ID is." The console showed device_id everywhere.
    bootConsole();

    Target::create([
        'device_id' => 42, 'protocol' => 'ssh', 'enabled' => true, 'flow' => 'database',
        'host_key_policy' => Target::POLICY_PIN, 'principal' => 'netops',
    ]);

    $this->actingAs(admin())
        ->get('plugin/webterm/admin?tab=targets')
        ->assertOk()
        // Standalone there is no LibreNMS Device model, so the name cannot
        // resolve -- but the link must still be there, and the row must still
        // identify itself rather than rendering blank.
        ->assertSee(url('device/42'))
        ->assertSee('#42');
});

it('escapes a device name rather than trusting it', function (): void {
    // A hostname is operator- and SNMP-influenced data, and the cell is now
    // rendered with {!! !!} so the link markup survives -- which means the NAME
    // has to be escaped by hand inside the helper.
    bootConsole();

    $html = view('WebTerm::admin', [
        'tab' => 'targets',
        'tabs' => ['targets'],
        'targets' => collect([(object) [
            'device_id' => 42, 'principal' => 'netops', 'flow' => 'database',
            'host_key_policy' => 'pin', 'enabled' => true,
        ]]),
        'grants' => collect(), 'abilities' => collect(), 'credentials' => collect(),
        'hostKeys' => collect(), 'sessions' => collect(), 'audit' => collect(),
        'deviceNames' => [42 => '<script>alert(1)</script>'],
        // Normally shared by ShareErrorsFromSession; absent when rendering the
        // view directly.
        'errors' => new ViewErrorBag,
    ])->render();

    expect($html)->not->toContain('<script>alert(1)</script>')
        ->and($html)->toContain('&lt;script&gt;');
});
