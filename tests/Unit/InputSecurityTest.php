<?php

declare(strict_types=1);

use PelicanProvisioner\Domain\EggAllowlist;
use PelicanProvisioner\Domain\EggVariableDefinition;
use PelicanProvisioner\Domain\EnvironmentBuilder;
use PelicanProvisioner\Domain\InvalidEnvironmentException;
use PelicanProvisioner\Domain\InvalidResourceAmountException;
use PelicanProvisioner\Domain\InvalidServerNameException;
use PelicanProvisioner\Domain\ResourceAmountParser;
use PelicanProvisioner\Domain\ResourceAmounts;
use PelicanProvisioner\Domain\ServerName;
use PelicanProvisioner\Domain\SubmittedVariables;
use PelicanProvisioner\Domain\UsageFactory;

it('parses aggregate decimals and fails closed on garbage', function () {
    $usage = UsageFactory::fromAggregate([
        'cpu' => '150.0',
        'memory' => 2048,
        'disk' => '0',
        'servers' => '2',
        'unlimited_cpu' => '0',
        'unlimited_memory' => '1',
        'unlimited_disk' => '0',
    ]);

    expect($usage->cpu)->toBe(150)
        ->and($usage->unlimitedMemoryServers)->toBe(1)
        ->and(UsageFactory::fromAggregate(['cpu' => 'nope'])->cpu)->toBe(ResourceAmounts::ABSOLUTE_MAX + 1);
});

it('parses only canonical resource integers', function () {
    expect(ResourceAmountParser::parse('0'))->toBe(0)
        ->and(ResourceAmountParser::parse(12))->toBe(12);

    expect(fn () => ResourceAmountParser::parse('1.5'))->toThrow(InvalidResourceAmountException::class)
        ->and(fn () => ResourceAmountParser::parse('01'))->toThrow(InvalidResourceAmountException::class)
        ->and(fn () => ResourceAmountParser::parse(-1))->toThrow(InvalidResourceAmountException::class)
        ->and(fn () => ResourceAmountParser::parse('1e2'))->toThrow(InvalidResourceAmountException::class);
});

it('rejects unsafe server names', function () {
    expect(ServerName::parse('  Skyblock  '))->toBe('Skyblock')
        ->and(fn () => ServerName::parse("bad\nname"))->toThrow(InvalidServerNameException::class)
        ->and(fn () => ServerName::parse(str_repeat('a', 256)))->toThrow(InvalidServerNameException::class);
});

it('rejects names that hide or reorder what they appear to say', function () {
    expect(ServerName::parse("Family \u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467} server"))->toContain('Family')
        ->and(fn () => ServerName::parse("bad\xFFname"))->toThrow(InvalidServerNameException::class)
        ->and(fn () => ServerName::parse("safe\u{202E}gnp.exe"))->toThrow(InvalidServerNameException::class)
        ->and(fn () => ServerName::parse("line\u{2028}break"))->toThrow(InvalidServerNameException::class)
        ->and(fn () => ServerName::parse("\u{200B}\u{200B}"))->toThrow(InvalidServerNameException::class)
        ->and(fn () => ServerName::parse('   '))->toThrow(InvalidServerNameException::class);
});

it('allows no egg unless it is listed or every egg is explicitly allowed', function () {
    $allowlist = new EggAllowlist();

    expect($allowlist->permits(4, [], false))->toBeFalse()
        ->and($allowlist->permits(4, [4, 8], false))->toBeTrue()
        ->and($allowlist->permits(5, [4, 8], false))->toBeFalse()
        ->and($allowlist->permits(4, [], true))->toBeTrue()
        ->and($allowlist->permits(0, [], true))->toBeFalse();
});

it('keeps hidden and locked egg variables on their defaults', function () {
    $builder = new EnvironmentBuilder();
    $definitions = [
        new EggVariableDefinition('SERVER_JAR', 'server.jar', true, true),
        new EggVariableDefinition('ADMIN_TOKEN', 'secret', false, false),
        new EggVariableDefinition('BUILD_PATH', '/defaults', true, false),
    ];

    $environment = $builder->build($definitions, [
        'SERVER_JAR' => 'paper.jar',
        'ADMIN_TOKEN' => 'pwned',
        'BUILD_PATH' => '/tmp/evil',
        'LD_PRELOAD' => '/tmp/lib.so',
    ]);

    expect($environment)->toBe([
        'SERVER_JAR' => 'paper.jar',
        'ADMIN_TOKEN' => 'secret',
        'BUILD_PATH' => '/defaults',
    ]);
});

it('rejects variable values that are not scalars', function () {
    $builder = new EnvironmentBuilder();

    expect(fn () => $builder->build(
        [new EggVariableDefinition('FLAG', '0', true, true)],
        ['FLAG' => ['nested' => true]],
    ))->toThrow(InvalidEnvironmentException::class);

    expect($builder->build(
        [new EggVariableDefinition('FLAG', '0', true, true)],
        ['FLAG' => true],
    )['FLAG'])->toBe('1');
});

it('stores the numbers and blanks the create form submits', function () {
    $builder = new EnvironmentBuilder();
    $definitions = [new EggVariableDefinition('FLAG', 'default', true, true)];

    expect($builder->build($definitions, ['FLAG' => 1.0])['FLAG'])->toBe('1')
        ->and($builder->build($definitions, ['FLAG' => 1.5])['FLAG'])->toBe('1.5')
        ->and($builder->build($definitions, ['FLAG' => 76561198000.0])['FLAG'])->toBe('76561198000')
        ->and($builder->build($definitions, ['FLAG' => null])['FLAG'])->toBe('')
        ->and($builder->build($definitions, [])['FLAG'])->toBe('default');
});

it('rejects numbers that can no longer be stored exactly', function () {
    $builder = new EnvironmentBuilder();
    $definitions = [new EggVariableDefinition('FLAG', '0', true, true)];

    expect(fn () => $builder->build($definitions, ['FLAG' => 9_007_199_254_740_992.0]))->toThrow(InvalidEnvironmentException::class)
        ->and(fn () => $builder->build($definitions, ['FLAG' => INF]))->toThrow(InvalidEnvironmentException::class)
        ->and(fn () => $builder->build($definitions, ['FLAG' => NAN]))->toThrow(InvalidEnvironmentException::class);
});

it('treats a row without a value as a locked field the form did not submit', function () {
    expect(SubmittedVariables::fromRepeater([
        ['env_variable' => 'LOCKED_PATH'],
        ['env_variable' => 'OPTIONAL_FLAG', 'variable_value' => null],
    ]))->toBe(['OPTIONAL_FLAG' => null]);
});

it('ignores forged repeater rows and rejects huge payloads', function () {
    expect(SubmittedVariables::fromRepeater([
        ['env_variable' => 'SERVER_JAR', 'variable_value' => 'paper.jar'],
        ['env_variable' => 'not valid', 'variable_value' => 'x'],
    ]))->toBe(['SERVER_JAR' => 'paper.jar']);

    expect(fn () => SubmittedVariables::fromRepeater(['nope']))->toThrow(InvalidEnvironmentException::class);

    expect(fn () => SubmittedVariables::fromRepeater(array_fill(0, 101, [
        'env_variable' => 'SERVER_JAR',
        'variable_value' => 'x',
    ])))->toThrow(InvalidEnvironmentException::class);
});
