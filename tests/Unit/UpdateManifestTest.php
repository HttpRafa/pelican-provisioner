<?php

declare(strict_types=1);

use PelicanProvisioner\Domain\UpdateManifest;

it('publishes one wildcard manifest for the release zip', function () {
    $root = dirname(__DIR__, 2);
    $plugin = json_decode((string) file_get_contents($root.'/plugin.json'), true, flags: JSON_THROW_ON_ERROR);
    $workflow = (string) file_get_contents($root.'/.github/workflows/release.yml');

    expect(UpdateManifest::document('0.4.0', 'abc123'))->toBe([
        '*' => [
            'version' => '0.4.0',
            'download_url' => 'https://github.com/HttpRafa/pelican-provisioner/releases/download/v0.4.0/pelican-provisioner-0.4.0.zip',
            'sha256' => 'abc123',
        ],
    ])
        ->and($plugin['version'])->toBe('0.4.0')
        ->and($plugin['update_url'])->toBe(UpdateManifest::updateUrl())
        ->and($workflow)->toContain('scripts/write-update-json.php')
        ->and($workflow)->toContain('dist/update.json');
});

it('only tells the release that replaced the tables to uninstall', function () {
    expect(UpdateManifest::releaseNotes('0.4.0'))->toContain('Uninstall')
        ->and(UpdateManifest::releaseNotes('0.4.1'))->not->toContain('Uninstall')
        ->and(UpdateManifest::releaseNotes('1.0.0'))->not->toContain('Uninstall');
});
