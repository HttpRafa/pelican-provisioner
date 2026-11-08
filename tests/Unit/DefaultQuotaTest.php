<?php

declare(strict_types=1);

use PelicanProvisioner\Domain\DefaultQuota;
use PelicanProvisioner\Domain\InvalidResourceAmountException;
use PelicanProvisioner\Domain\Quota;
use PelicanProvisioner\Domain\QuotaLimits;
use PelicanProvisioner\Domain\ResourceAmounts;

it('does not turn a missing default into an unlimited quota', function () {
    expect(DefaultQuota::parse([]))->toBeNull()
        ->and(DefaultQuota::parse([
            'default_cpu' => '',
            'default_memory' => '   ',
            'default_disk' => null,
        ]))->toBeNull();
});

it('refuses an unlimited default for CPU, memory, or disk', function (string $field) {
    $values = ['default_cpu' => '100', 'default_memory' => '1024', 'default_disk' => '2048', 'default_server_limit' => '1'];
    $values[$field] = '0';

    expect(fn () => DefaultQuota::parse($values))->toThrow(InvalidResourceAmountException::class);
})->with(['default_cpu', 'default_memory', 'default_disk']);

it('allows a default with no cap on the number of servers', function () {
    $quota = DefaultQuota::parse([
        'default_cpu' => '100',
        'default_memory' => 1024,
        'default_disk' => '2048',
        'default_server_limit' => '0',
    ]);

    expect($quota)->toEqual(new Quota(100, 1024, 2048, 0))
        ->and($quota?->serverCap())->toBeNull();
});

it('uses the same amount checks as a quota an administrator types in', function () {
    expect(DefaultQuota::parse([
        'default_cpu' => '100',
        'default_memory' => '2048',
        'default_disk' => '10240',
        'default_server_limit' => '2',
    ]))->toEqual(new Quota(100, 2048, 10240, 2))
        ->and(QuotaLimits::parse(0))->toBe(0)
        ->and(QuotaLimits::parse(ResourceAmounts::ABSOLUTE_MAX))->toBe(ResourceAmounts::ABSOLUTE_MAX);

    expect(fn () => DefaultQuota::parse([
        'default_cpu' => '100',
        'default_memory' => '',
        'default_disk' => '1',
        'default_server_limit' => '1',
    ]))->toThrow(InvalidResourceAmountException::class)
        ->and(fn () => DefaultQuota::parse([
            'default_cpu' => '-1',
            'default_memory' => '1',
            'default_disk' => '1',
            'default_server_limit' => '1',
        ]))->toThrow(InvalidResourceAmountException::class)
        ->and(fn () => QuotaLimits::parse(ResourceAmounts::ABSOLUTE_MAX + 1))->toThrow(InvalidResourceAmountException::class)
        ->and(fn () => QuotaLimits::parse('1.5'))->toThrow(InvalidResourceAmountException::class)
        ->and(fn () => QuotaLimits::parse('01'))->toThrow(InvalidResourceAmountException::class);
});
