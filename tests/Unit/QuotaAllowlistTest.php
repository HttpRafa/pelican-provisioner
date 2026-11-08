<?php

declare(strict_types=1);

use PelicanProvisioner\Domain\AccessMode;
use PelicanProvisioner\Domain\EggAllowlist;
use PelicanProvisioner\Domain\QuotaAllowlist;

it('keeps the global rules when a quota has no mode', function () {
    $allowlist = new QuotaAllowlist();
    $eggs = new EggAllowlist();
    $choice = $allowlist->eggs(false, [5], null, [9]);
    $open = $allowlist->eggs(true, [], null, [9]);

    expect($eggs->permits(5, $choice->ids, $choice->allowAll, $choice->excludedIds))->toBeTrue()
        ->and($eggs->permits(9, $choice->ids, $choice->allowAll, $choice->excludedIds))->toBeFalse()
        ->and($open->allowAll)->toBeTrue()
        ->and($eggs->permits(9, $open->ids, $open->allowAll, $open->excludedIds))->toBeTrue()
        ->and($allowlist->nodes([1, 2], null, [3]))->toBe([1, 2])
        ->and($allowlist->placement(null, [1, 2], null)->nodeIds)->toBe([1, 2])
        ->and($allowlist->placement(1, [1, 2], null)->rejected)->toBeTrue();
});

it('adds eggs and nodes the global rules do not allow', function () {
    $allowlist = new QuotaAllowlist();
    $eggs = new EggAllowlist();
    $choice = $allowlist->eggs(false, [5], AccessMode::Additive, [9]);

    expect($eggs->permits(5, $choice->ids, $choice->allowAll, $choice->excludedIds))->toBeTrue()
        ->and($eggs->permits(9, $choice->ids, $choice->allowAll, $choice->excludedIds))->toBeTrue()
        ->and($allowlist->nodes([1], AccessMode::Additive, [4]))->toBe([1, 4])
        ->and($allowlist->placement(4, [1, 4], AccessMode::Additive)->nodeIds)->toBe([4]);
});

it('removes eggs and nodes from the global rules', function () {
    $allowlist = new QuotaAllowlist();
    $eggs = new EggAllowlist();
    $finite = $allowlist->eggs(false, [5, 6], AccessMode::Negative, [6]);
    $open = $allowlist->eggs(true, [], AccessMode::Negative, [6]);

    expect($eggs->permits(5, $finite->ids, $finite->allowAll, $finite->excludedIds))->toBeTrue()
        ->and($eggs->permits(6, $finite->ids, $finite->allowAll, $finite->excludedIds))->toBeFalse()
        ->and($eggs->permits(8, $open->ids, $open->allowAll, $open->excludedIds))->toBeTrue()
        ->and($eggs->permits(6, $open->ids, $open->allowAll, $open->excludedIds))->toBeFalse()
        ->and($allowlist->nodes([1, 2], AccessMode::Negative, [2]))->toBe([1]);
});

it('replaces the global rules with the quota selection', function () {
    $allowlist = new QuotaAllowlist();
    $eggs = new EggAllowlist();
    $oneEgg = $allowlist->eggs(false, [5, 6], AccessMode::Overwrite, [9]);
    $none = $allowlist->eggs(true, [], AccessMode::Overwrite, []);
    $everyEgg = $allowlist->eggs(false, [5], AccessMode::Overwrite, [], true);
    $oneNode = $allowlist->nodes([1, 2], AccessMode::Overwrite, [4]);

    expect($eggs->permits(9, $oneEgg->ids, $oneEgg->allowAll, $oneEgg->excludedIds))->toBeTrue()
        ->and($eggs->permits(5, $oneEgg->ids, $oneEgg->allowAll, $oneEgg->excludedIds))->toBeFalse()
        ->and($eggs->permits(5, $none->ids, $none->allowAll, $none->excludedIds))->toBeFalse()
        ->and($eggs->permits(12, $everyEgg->ids, $everyEgg->allowAll, $everyEgg->excludedIds))->toBeTrue()
        ->and($oneNode)->toBe([4])
        ->and($allowlist->placement(4, $oneNode, AccessMode::Overwrite)->rejected)->toBeFalse()
        ->and($allowlist->placement(1, $oneNode, AccessMode::Overwrite)->rejected)->toBeTrue()
        ->and($allowlist->placement(null, $oneNode, AccessMode::Overwrite)->rejected)->toBeTrue();
});

it('reads node lists by their values, so a filtered list keeps its nodes', function () {
    $allowlist = new QuotaAllowlist();

    expect($allowlist->nodes([1], AccessMode::Overwrite, [0 => 4, 2 => 8]))->toBe([4, 8])
        ->and($allowlist->nodes([0 => 1, 2 => 2], AccessMode::Negative, []))->toBe([1, 2]);
});

it('is applied when a server is created, not only in the form', function () {
    $root = dirname(__DIR__, 2);
    $provisioner = (string) file_get_contents($root.'/src/Services/ServerProvisioner.php');
    $page = (string) file_get_contents($root.'/src/Filament/App/Pages/CreateServerPage.php');

    expect($provisioner)->toContain('eggMode')
        ->and($provisioner)->toContain('nodeMode')
        ->and($provisioner)->toContain('allowAllEggs')
        ->and($provisioner)->toContain('$this->allowlist->eggs(')
        ->and($provisioner)->toContain('$this->allowlist->placement(')
        ->and($page)->toContain('->map(fn (Node $node): int => (int) $node->id)')
        ->and($page)->toContain('->values()')
        ->and($provisioner)->toContain('->values()');
});

it('reads a stored list whose keys have gaps, so a remove list still removes', function () {
    $allowlist = new QuotaAllowlist();
    $gapped = [1 => 5, 3 => 7];

    expect(QuotaAllowlist::ids($gapped))->toBe([5, 7])
        ->and(QuotaAllowlist::ids('{"1":5,"3":7}'))->toBe([5, 7])
        ->and($allowlist->eggs(false, [4, 5, 7], 'negative', $gapped)->ids)->toBe([4])
        ->and($allowlist->eggs(true, [], 'negative', $gapped)->excludedIds)->toBe([5, 7])
        ->and($allowlist->nodes([1, 5, 7], 'negative', $gapped))->toBe([1]);
});
