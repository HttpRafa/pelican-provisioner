<?php

declare(strict_types=1);

use PelicanProvisioner\Domain\UpdateManifest;

it('publishes one wildcard manifest for the release zip', function () {
    $root = dirname(__DIR__, 2);
    $plugin = json_decode((string) file_get_contents($root.'/plugin.json'), true, flags: JSON_THROW_ON_ERROR);
    $workflow = (string) file_get_contents($root.'/.github/workflows/release.yml');

    expect(UpdateManifest::document('0.4.0'))->toBe([
        '*' => [
            'version' => '0.4.0',
            'download_url' => 'https://github.com/HttpRafa/pelican-provisioner/releases/download/v0.4.0/pelican-provisioner-0.4.0.zip',
        ],
    ])
        ->and($plugin['version'])->toBe('0.4.0')
        ->and($plugin['update_url'])->toBe(UpdateManifest::updateUrl())
        ->and($workflow)->toContain('scripts/write-update-json.php')
        ->and($workflow)->toContain('dist/update.json');
});
