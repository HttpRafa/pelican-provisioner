<?php

declare(strict_types=1);

use PelicanProvisioner\Domain\ProvisionerLog;
use PelicanProvisioner\Domain\Quota;
use PelicanProvisioner\Domain\QuotaHistory;

it('records who changed a quota and the old and new limits', function () {
    $created = QuotaHistory::properties('ada', null, new Quota(0, 2048, 10240, 2));
    $updated = QuotaHistory::properties('ada', new Quota(100, 512, 1024, 1), new Quota(200, 1024, 2048, 0));
    $deleted = QuotaHistory::properties('ada', new Quota(200, 1024, 2048, 0), null);

    expect(QuotaHistory::EVENT)->toBe('user:provisioner.quota')
        ->and($created['actor'])->toBe('ada')
        ->and($created['old_cpu'])->toBe('—')
        ->and($created['new_cpu'])->toBe('0')
        ->and($created['new_memory'])->toBe('2048')
        ->and($created['new_servers'])->toBe('2')
        ->and($updated['old_cpu'])->toBe('100')
        ->and($updated['new_cpu'])->toBe('200')
        ->and($updated['old_servers'])->toBe('1')
        ->and($updated['new_servers'])->toBe('0')
        ->and($deleted['new_disk'])->toBe('—')
        ->and($deleted['old_disk'])->toBe('2048');
});

it('keeps a zero limit distinct from a quota that did not exist', function () {
    $properties = QuotaHistory::properties('ada', new Quota(0, 0, 0, 0), new Quota(0, 0, 0, null));

    expect($properties['old_cpu'])->toBe('0')
        ->and($properties['new_servers'])->toBe('—')
        ->and(QuotaHistory::properties('  <b>ada</b>  ', null, null)['actor'])->toBe('ada');
});

it('names the server, owner, egg, and size in the panel log', function () {
    $created = ProvisionerLog::created('Survival', 'ada', 'Paper', 100, 2048, 10240);
    $denied = ProvisionerLog::denied('ada', 'resize', 'not_owner', 'Survival');
    $sync = ProvisionerLog::syncFailed('Survival', 'ada');

    expect($created)->toBe('Created server "Survival" for ada with the Paper egg (100% CPU, 2048 memory, 10240 disk).')
        ->and($denied)->toContain('ada')
        ->and($denied)->toContain('Survival')
        ->and($denied)->toContain('not_owner')
        ->and($sync)->toContain('Survival')
        ->and($sync)->toContain('ada')
        ->and($created)->not->toBe('pelican-provisioner.created')
        ->and($denied)->not->toBe('pelican-provisioner.denied');
});

it('shows that sentence to the quota owner and keeps the old server events', function () {
    $root = dirname(__DIR__, 2);
    $observer = (string) file_get_contents($root.'/src/Observers/RecordQuotaHistory.php');
    $provider = (string) file_get_contents($root.'/src/Providers/PelicanProvisionerServiceProvider.php');
    $provisioner = (string) file_get_contents($root.'/src/Services/ServerProvisioner.php');
    $english = (string) file_get_contents($root.'/lang/en/activity.php');

    expect($observer)->toContain('->subject($owner)')
        ->and($observer)->toContain('QuotaHistory::EVENT')
        ->and($observer)->toContain('->actor($actor)')
        ->and($provider)->toContain('RecordQuotaHistory')
        ->and($english)->toContain(':actor')
        ->and($english)->toContain(':old_cpu')
        ->and($english)->toContain(':new_servers')
        ->and($provisioner)->toContain('ProvisionerLog::created')
        ->and($provisioner)->toContain('ProvisionerLog::denied')
        ->and($provisioner)->toContain('ProvisionerLog::syncFailed')
        ->and($provisioner)->toContain("'server:provisioner.create'")
        ->and($provisioner)->toContain("'server:provisioner.resources'")
        ->and($provisioner)->toContain("'server:provisioner.delete'");
});

it('describes an access change with its own event and fields', function () {
    expect(QuotaHistory::ACCESS_EVENT)->toBe('user:provisioner.quota_access')
        ->and(QuotaHistory::access('  <i>ada</i> ', 'Add to the global list (2)', 'Use the global rules'))->toBe([
            'actor' => 'ada',
            'eggs' => 'Add to the global list (2)',
            'nodes' => 'Use the global rules',
        ])
        ->and(array_intersect(QuotaHistory::LIMIT_FIELDS, QuotaHistory::ACCESS_FIELDS))->toBe([]);
});
