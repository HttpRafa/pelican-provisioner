<?php

declare(strict_types=1);

use PelicanProvisioner\Domain\Grant;
use PelicanProvisioner\Domain\GrantResolver;
use PelicanProvisioner\Domain\GrantSource;
use PelicanProvisioner\Domain\Quota;

it('refuses a banned user before an override or the default', function () {
    $override = Grant::override(new Quota(10, 20, 30, 1), null, null, false, null, null);
    $decision = GrantResolver::decide(true, $override, new Quota(100, 100, 100, 1));

    expect($decision->allowed())->toBeFalse()
        ->and($decision->reason)->toBe('banned')
        ->and($decision->grant)->toBeNull();
});

it('uses an override ahead of the saved default', function () {
    $override = Grant::override(new Quota(10, 20, 30, 1), 'overwrite', [4], false, null, null);
    $decision = GrantResolver::decide(false, $override, new Quota(100, 100, 100, 2));

    expect($decision->allowed())->toBeTrue()
        ->and($decision->grant)->toBe($override)
        ->and($decision->grant?->source)->toBe(GrantSource::Override);
});

it('grants the saved default when the user has no override', function () {
    $decision = GrantResolver::decide(false, null, new Quota(100, 1024, 2048, 0));

    expect($decision->allowed())->toBeTrue()
        ->and($decision->grant?->source)->toBe(GrantSource::Default)
        ->and($decision->grant?->quota)->toEqual(new Quota(100, 1024, 2048, 0))
        ->and($decision->grant?->quota->serverCap())->toBeNull()
        ->and($decision->grant?->eggMode)->toBeNull()
        ->and($decision->grant?->nodeMode)->toBeNull();
});

it('refuses a user with no override when no default is saved', function () {
    $decision = GrantResolver::decide(false, null, null);

    expect($decision->allowed())->toBeFalse()
        ->and($decision->reason)->toBe('no_quota');
});
