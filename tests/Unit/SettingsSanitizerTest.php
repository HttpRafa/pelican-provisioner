<?php

declare(strict_types=1);

use PelicanProvisioner\Domain\InvalidSettingsException;
use PelicanProvisioner\Domain\SettingsSanitizer;

function sanitizer(): SettingsSanitizer
{
    return new SettingsSanitizer();
}

/** @return array<string, mixed> */
function storedSettings(): array
{
    return [
        'database_limit' => '0',
        'allocation_limit' => '0',
        'backup_limit' => '0',
        'subdomain_limit' => '0',
        'can_users_update_servers' => 'true',
        'can_users_delete_servers' => 'false',
        'deployment_tags' => 'nodes',
        'deployment_ports' => '',
        'allowed_eggs' => '5',
        'allow_all_eggs' => 'false',
    ];
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
        'allow_all_eggs' => 'false',
    ]);

    expect($settings->createUsable)->toBeTrue()
        ->and($settings->usersCanUpdate)->toBeFalse()
        ->and($settings->usersCanDelete)->toBeTrue()
        ->and($settings->ports)->toBe([])
        ->and($settings->allowedEggIds)->toBe([])
        ->and($settings->allowAllEggs)->toBeFalse();
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
        'allow_all_eggs' => true,
    ]);

    expect($settings->usersCanUpdate)->toBeFalse()
        ->and($settings->usersCanDelete)->toBeTrue()
        ->and($settings->allowedEggIds)->toBe([4, 8])
        ->and($settings->allowAllEggs)->toBeTrue()
        ->and($settings->ports)->toBe(['25565', '25565-25575']);
});

it('refuses to widen a corrupt tag or port list to everything', function () {
    $form = [
        'database_limit' => '0',
        'allocation_limit' => '0',
        'backup_limit' => '0',
        'can_users_update_servers' => true,
        'can_users_delete_servers' => false,
        'allow_all_eggs' => false,
        'deployment_tags' => [],
        'deployment_ports' => [],
        'allowed_eggs' => [],
    ];

    expect(fn () => sanitizer()->toEnvironment($form, false, ['deployment_tags' => "lab\nEVIL=1", 'subdomain_limit' => '0']))->toThrow(InvalidSettingsException::class)
        ->and(fn () => sanitizer()->toEnvironment($form, false, ['deployment_ports' => '80-90', 'subdomain_limit' => '0']))->toThrow(InvalidSettingsException::class);
});

it('lets an empty egg list repair a corrupt one, because it allows nothing', function () {
    $environment = sanitizer()->toEnvironment([
        'database_limit' => '0',
        'allocation_limit' => '0',
        'backup_limit' => '0',
        'can_users_update_servers' => true,
        'can_users_delete_servers' => false,
        'allow_all_eggs' => false,
        'deployment_tags' => ['nodes'],
        'deployment_ports' => [],
        'allowed_eggs' => [],
    ], false, ['allowed_eggs' => 'abc', 'subdomain_limit' => '0']);

    expect($environment['PROVISIONER_ALLOWED_EGGS'])->toBe('')
        ->and($environment['PROVISIONER_ALLOW_ALL_EGGS'])->toBe('false');
});

it('leaves creation on when no default quota is saved', function () {
    $settings = sanitizer()->fromConfig(storedSettings());

    expect($settings->createUsable)->toBeTrue()
        ->and($settings->defaultQuota)->toBeNull();
});

it('turns creation off when the default quota is corrupt', function () {
    $settings = sanitizer()->fromConfig(storedSettings() + [
        'default_cpu' => '100',
        'default_memory' => '',
        'default_disk' => '1',
        'default_server_limit' => '1',
    ]);

    expect($settings->createUsable)->toBeFalse()
        ->and($settings->defaultQuota)->toBeNull()
        ->and(fn () => sanitizer()->toEnvironment([
            'database_limit' => '0',
            'allocation_limit' => '0',
            'backup_limit' => '0',
            'can_users_update_servers' => true,
            'can_users_delete_servers' => false,
            'allow_all_eggs' => false,
            'deployment_tags' => ['nodes'],
            'deployment_ports' => [],
            'allowed_eggs' => [],
            'default_cpu' => '-1',
            'default_memory' => '1',
            'default_disk' => '1',
            'default_server_limit' => '1',
        ], false, storedSettings()))->toThrow(InvalidSettingsException::class);
});

it('turns creation off when a stored setting is missing or corrupt', function (string $key, mixed $value) {
    expect(sanitizer()->fromConfig([$key => $value] + storedSettings())->createUsable)->toBeFalse();
})->with([
    'missing egg list' => ['allowed_eggs', null],
    'commas only' => ['allowed_eggs', ',,'],
    'blank entries only' => ['allowed_eggs', ' , '],
    'unreadable eggs' => ['allowed_eggs', 'abc'],
    'missing tags' => ['deployment_tags', null],
    'commas instead of tags' => ['deployment_tags', ','],
    'missing ports' => ['deployment_ports', null],
    'missing allow-all switch' => ['allow_all_eggs', null],
    'unreadable allow-all switch' => ['allow_all_eggs', 'maybe'],
]);

it('refuses tags the environment loader would expand', function () {
    expect(sanitizer()->tags('${SOME_VALUE}'))->toBeNull()
        ->and(sanitizer()->tags('$SOME_VALUE'))->toBeNull()
        ->and(sanitizer()->tags(['eu-west', 'EU (West) #2']))->toBe(['eu-west', 'EU (West) #2']);
});

it('writes only safe environment values', function () {
    $environment = sanitizer()->toEnvironment([
        'database_limit' => '1',
        'allocation_limit' => '2',
        'backup_limit' => '3',
        'subdomain_limit' => '4',
        'can_users_update_servers' => false,
        'can_users_delete_servers' => false,
        'allow_all_eggs' => false,
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
        'allow_all_eggs' => 'false',
    ]);

    expect($settings->createUsable)->toBeFalse()
        ->and(sanitizer()->validPort('25565'))->toBeTrue()
        ->and(sanitizer()->validPort('1024-2023'))->toBeTrue()
        ->and(sanitizer()->validPort('1024-2024'))->toBeFalse()
        ->and(sanitizer()->validPort('2000-1000'))->toBeFalse()
        ->and(sanitizer()->validPort('0'))->toBeFalse();
});
