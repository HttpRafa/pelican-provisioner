<?php

declare(strict_types=1);

use PelicanProvisioner\Domain\InvalidSettingsException;
use PelicanProvisioner\Domain\SettingsSanitizer;

function sanitizer(): SettingsSanitizer
{
    return new SettingsSanitizer();
}

it('treats the string false as false', function () {
    $settings = sanitizer()->fromConfig([
        'database_limit' => '0',
        'allocation_limit' => '1',
        'backup_limit' => '2',
        'subdomain_limit' => '3',
        'can_users_update_servers' => 'false',
        'can_users_delete_servers' => 'true',
        'deployment_tags' => 'user_creatable_servers',
        'deployment_ports' => '',
        'allowed_eggs' => '',
    ]);

    expect($settings->createUsable)->toBeTrue()
        ->and($settings->usersCanUpdate)->toBeFalse()
        ->and($settings->usersCanDelete)->toBeTrue()
        ->and($settings->ports)->toBe([])
        ->and($settings->allowedEggIds)->toBe([]);
});

it('accepts boolean values already cast by the environment loader', function () {
    $settings = sanitizer()->fromConfig([
        'database_limit' => 0,
        'allocation_limit' => 0,
        'backup_limit' => 0,
        'subdomain_limit' => 0,
        'can_users_update_servers' => false,
        'can_users_delete_servers' => true,
        'deployment_tags' => ['one', 'two'],
        'deployment_ports' => ['25565', '25565-25575'],
        'allowed_eggs' => ['4', 8],
    ]);

    expect($settings->usersCanUpdate)->toBeFalse()
        ->and($settings->usersCanDelete)->toBeTrue()
        ->and($settings->allowedEggIds)->toBe([4, 8])
        ->and($settings->ports)->toBe(['25565', '25565-25575']);
});

it('refuses to widen a corrupt tag, port, or egg list to everything', function () {
    $stored = [
        'deployment_tags' => "lab\nEVIL=1",
        'deployment_ports' => '80-90',
        'allowed_eggs' => 'abc',
        'subdomain_limit' => '0',
    ];

    expect(fn () => sanitizer()->toEnvironment([
        'database_limit' => '0',
        'allocation_limit' => '0',
        'backup_limit' => '0',
        'can_users_update_servers' => true,
        'can_users_delete_servers' => false,
        'deployment_tags' => [],
        'deployment_ports' => [],
        'allowed_eggs' => [],
    ], false, $stored))->toThrow(InvalidSettingsException::class);
});

it('writes only safe environment values', function () {
    $environment = sanitizer()->toEnvironment([
        'database_limit' => '1',
        'allocation_limit' => '2',
        'backup_limit' => '3',
        'subdomain_limit' => '4',
        'can_users_update_servers' => false,
        'can_users_delete_servers' => false,
        'deployment_tags' => ['user_creatable_servers'],
        'deployment_ports' => ['25565-25665'],
        'allowed_eggs' => [9],
    ], true, []);

    expect($environment['PROVISIONER_CAN_USERS_UPDATE_SERVERS'])->toBe('false')
        ->and($environment['PROVISIONER_DEPLOYMENT_TAGS'])->toBe('user_creatable_servers')
        ->and($environment['PROVISIONER_ALLOWED_EGGS'])->toBe('9')
        ->and(implode('', $environment))->not->toContain("\n");
});

it('fails closed when a port range would be ignored or too large', function () {
    $settings = sanitizer()->fromConfig([
        'database_limit' => '0',
        'allocation_limit' => '0',
        'backup_limit' => '0',
        'subdomain_limit' => '0',
        'can_users_update_servers' => 'true',
        'can_users_delete_servers' => 'false',
        'deployment_tags' => 'nodes',
        'deployment_ports' => '80-90',
        'allowed_eggs' => '1',
    ]);

    expect($settings->createUsable)->toBeFalse()
        ->and(sanitizer()->validPort('25565'))->toBeTrue()
        ->and(sanitizer()->validPort('1024-2023'))->toBeTrue()
        ->and(sanitizer()->validPort('1024-2024'))->toBeFalse()
        ->and(sanitizer()->validPort('2000-1000'))->toBeFalse()
        ->and(sanitizer()->validPort('0'))->toBeFalse();
});
