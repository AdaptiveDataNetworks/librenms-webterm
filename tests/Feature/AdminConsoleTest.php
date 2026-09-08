<?php

declare(strict_types=1);

use AdaptiveDataNetworks\WebTerm\Models\Ability;
use AdaptiveDataNetworks\WebTerm\Models\AuditEntry;
use AdaptiveDataNetworks\WebTerm\Models\Credential;
use AdaptiveDataNetworks\WebTerm\Models\Grant;
use AdaptiveDataNetworks\WebTerm\Tests\Fakes\FakePluginManager;
use AdaptiveDataNetworks\WebTerm\Tests\Support\FakeUser;
use AdaptiveDataNetworks\WebTerm\WebTermServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
