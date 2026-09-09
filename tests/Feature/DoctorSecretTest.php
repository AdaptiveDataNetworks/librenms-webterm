<?php

declare(strict_types=1);

use AdaptiveDataNetworks\WebTerm\Console\ConfigCommand;
use AdaptiveDataNetworks\WebTerm\Console\DoctorCommand;

/*
| A real installation hit this: the secret file existed, the librenms user
| could not read it, and doctor said "librenms-webterm-gw init". On a working
| install that would replace a secret the gateway is already using and break
| every session mint -- so the guidance had to distinguish the two cases.
*/

it('never tells an operator to regenerate a secret that already exists', function () {
    $fix = DoctorCommand::unreadableSecretRemediation('/etc/librenms-webterm/gateway.secret', 'librenms');

    expect($fix)->toContain('do NOT regenerate it')
        ->and($fix)->not->toContain('init')
        ->and($fix)->not->toContain('--force');
});

it('names the actual user and path rather than "the web user"', function () {
    $fix = DoctorCommand::unreadableSecretRemediation('/etc/librenms-webterm/gateway.secret', 'librenms');

    expect($fix)->toContain('usermod -a -G librenms-webterm librenms')
        ->and($fix)->toContain('chown root:librenms-webterm /etc/librenms-webterm/gateway.secret')
        ->and($fix)->toContain('chmod 0640 /etc/librenms-webterm/gateway.secret');
});

it('mentions the two steps people forget', function () {
    // Group membership does not reach processes that are already running, so
    // both the login shell and php-fpm have to be restarted.
    $fix = DoctorCommand::unreadableSecretRemediation('/x', 'someuser');

    expect($fix)->toContain('log out and back in')
        ->and($fix)->toContain('php-fpm');
});

it('tells the operator to create a secret that is genuinely missing', function () {
    config()->set('webterm.gateway.secret_file', '/tmp/claude-1000/sec/does-not-exist.secret');

    $this->artisan('webterm:doctor')
        ->expectsOutputToContain('does not exist')
        ->expectsOutputToContain('init --path');
});

it('never tells an operator to run a config command the plugin refuses', function (): void {
    // webterm:config REFUSES gateway.secret_file, yet four shipped places told
    // operators to run exactly that -- including doctor's own remediation, so
    // the one moment an operator most needs a working instruction was the one
    // that handed them a command that errors.
    $refused = (new ReflectionClass(ConfigCommand::class))
        ->getConstant('REFUSED');

    $shipped = [
        'src/Console/DoctorCommand.php',
        'packaging/install.sh',
        'packaging/scripts/postinstall.sh',
        'docs/install/bare-metal.md',
        'docs/getting-started/quickstart.md',
    ];

    foreach ($shipped as $file) {
        $body = (string) file_get_contents(__DIR__.'/../../'.$file);

        foreach ($refused as $key) {
            expect($body)->not->toContain('config set '.$key, sprintf('%s tells operators to set a refused key', $file));
        }
    }
});
