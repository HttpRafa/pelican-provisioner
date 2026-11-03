<?php

declare(strict_types=1);

use PelicanProvisioner\Domain\Quota;
use PelicanProvisioner\Domain\QuotaPolicy;
use PelicanProvisioner\Domain\QuotaResource;
use PelicanProvisioner\Domain\QuotaViolationException;
use PelicanProvisioner\Domain\ResourceAmounts;
use PelicanProvisioner\Domain\Usage;

function quotaPolicy(): QuotaPolicy
{
    return new QuotaPolicy();
}

it('allows a request that fits a finite quota', function () {
    $quota = new Quota(200, 2048, 4096, 2);
    $usage = Usage::fromServers([new ResourceAmounts(50, 512, 1024)]);

    quotaPolicy()->assertCanCreate($quota, $usage, new ResourceAmounts(100, 512, 1024));

    expect(quotaPolicy()->maximum(200, 50, 0))->toBe(150);
});

it('rejects a request over the remaining quota', function () {
    $quota = new Quota(100, 1024, 1024, null);

    expect(fn () => quotaPolicy()->assertCanCreate(
        $quota,
        Usage::fromServers([new ResourceAmounts(80, 0, 0)]),
        new ResourceAmounts(30, 128, 128),
    ))->toThrow(fn (QuotaViolationException $exception) => $exception->resource === QuotaResource::Cpu);
});

it('rejects an unlimited server when the quota is finite', function () {
    expect(fn () => quotaPolicy()->assertCanCreate(
        new Quota(100, 1024, 1024, null),
        Usage::none(),
        new ResourceAmounts(0, 128, 128),
    ))->toThrow(QuotaViolationException::class);
});

it('allows an unlimited server when that resource quota is unlimited', function () {
    quotaPolicy()->assertCanCreate(
        new Quota(0, 0, 0, null),
        Usage::none(),
        new ResourceAmounts(0, 0, 0),
    );

    expect(quotaPolicy()->maximum(0, 0, 0))->toBeNull();
});

it('treats an existing unlimited server as consuming a finite quota', function () {
    $usage = Usage::fromServers([new ResourceAmounts(0, 512, 512)]);

    expect(fn () => quotaPolicy()->assertCanCreate(
        new Quota(200, 4096, 4096, null),
        $usage,
        new ResourceAmounts(50, 512, 512),
    ))->toThrow(fn (QuotaViolationException $exception) => $exception->resource === QuotaResource::Cpu);
});

it('lets an owner turn their unlimited server into a finite one that fits', function () {
    $usage = Usage::fromServers([new ResourceAmounts(0, 512, 512)]);

    expect(fn () => quotaPolicy()->assertCanUpdate(
        new Quota(200, 4096, 4096, null),
        $usage,
        new ResourceAmounts(0, 512, 512),
        new ResourceAmounts(100, 512, 512),
    ))->not->toThrow(QuotaViolationException::class);
});

it('lets an owner who is over quota shrink but not grow', function () {
    $quota = new Quota(100, 1024, 1024, null);
    $current = new ResourceAmounts(90, 512, 512);
    $usage = Usage::fromServers([$current, $current, $current]);

    expect(fn () => quotaPolicy()->assertCanUpdate($quota, $usage, $current, new ResourceAmounts(10, 512, 256)))->not->toThrow(QuotaViolationException::class)
        ->and(fn () => quotaPolicy()->assertCanUpdate($quota, $usage, $current, new ResourceAmounts(91, 512, 512)))->toThrow(QuotaViolationException::class);
});

it('does not treat a resize to unlimited as shrinking', function () {
    $current = new ResourceAmounts(90, 512, 512);

    expect(fn () => quotaPolicy()->assertCanUpdate(
        new Quota(100, 1024, 1024, null),
        Usage::fromServers([$current]),
        $current,
        new ResourceAmounts(0, 512, 512),
    ))->toThrow(QuotaViolationException::class);
});

it('measures only the growth of a resize', function () {
    $growth = (new ResourceAmounts(150, 100, 512))->growthFrom(new ResourceAmounts(100, 200, 512));

    expect($growth)->toEqual(new ResourceAmounts(50, 0, 0))
        ->and($growth->isZero())->toBeFalse()
        ->and((new ResourceAmounts(1, 1, 1))->growthFrom(new ResourceAmounts(2, 2, 2))->isZero())->toBeTrue();
});

it('enforces the server count and treats zero as no cap', function () {
    expect(fn () => quotaPolicy()->assertCanCreate(
        new Quota(0, 0, 0, 1),
        Usage::fromServers([new ResourceAmounts(10, 10, 10)]),
        new ResourceAmounts(10, 10, 10),
    ))->toThrow(fn (QuotaViolationException $exception) => $exception->resource === QuotaResource::Servers);

    quotaPolicy()->assertCanCreate(
        new Quota(0, 0, 0, 0),
        Usage::fromServers([new ResourceAmounts(10, 10, 10)]),
        new ResourceAmounts(10, 10, 10),
    );
});

it('fails closed when stored usage is already over the cap', function () {
    expect(fn () => quotaPolicy()->assertWithin(
        new Quota(100, 1024, 1024, 1),
        new Usage(150, 100, 100, 1, 0, 0, 0),
    ))->toThrow(QuotaViolationException::class);
});

it('rejects values above the database ceiling', function () {
    expect(fn () => quotaPolicy()->assertCanCreate(
        new Quota(0, 0, 0, null),
        Usage::none(),
        new ResourceAmounts(ResourceAmounts::ABSOLUTE_MAX + 1, 1, 1),
    ))->toThrow(QuotaViolationException::class);
});
