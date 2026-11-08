<?php

declare(strict_types=1);

use PelicanProvisioner\Domain\UpdateManifest;

require dirname(__DIR__).'/vendor/autoload.php';

$root = dirname(__DIR__);
$plugin = json_decode((string) file_get_contents($root.'/plugin.json'), true, flags: JSON_THROW_ON_ERROR);
$version = $plugin['version'] ?? null;

if (!is_string($version) || $version === '') {
    fwrite(STDERR, "plugin.json needs a version.\n");

    exit(1);
}

$directory = $root.'/dist';

if (!is_dir($directory) && !mkdir($directory, recursive: true) && !is_dir($directory)) {
    fwrite(STDERR, "Could not create $directory.\n");

    exit(1);
}

$manifest = $directory.'/update.json';
$notes = $directory.'/release-notes.md';

file_put_contents($manifest, json_encode(UpdateManifest::document($version), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
file_put_contents($notes, "Uninstall Pelican Provisioner before installing this zip. Uninstall drops the quota and ban tables. The new install starts empty.\n");

echo $manifest."\n";
