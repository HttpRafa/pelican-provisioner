<?php

declare(strict_types=1);

namespace PelicanProvisioner\Domain;

/**
 * The document Pelican fetches from update_url. The wildcard applies to every panel version. The download is the zip the release workflow already builds. The panel refuses a download that does not match the checksum, and the zip is code it will run.
 */
final class UpdateManifest
{
    public const string REPOSITORY = 'https://github.com/HttpRafa/pelican-provisioner';

    /**
     * @return array<string, array{version: string, download_url: string, sha256: string}>
     */
    public static function document(string $version, string $sha256): array
    {
        return [
            '*' => [
                'version' => $version,
                'download_url' => self::REPOSITORY.'/releases/download/v'.$version.'/pelican-provisioner-'.$version.'.zip',
                'sha256' => $sha256,
            ],
        ];
    }

    /** Only 0.4.0 replaced the tables. Telling later releases to uninstall would cost every administrator their overrides and bans. */
    public static function releaseNotes(string $version): string
    {
        if ($version === '0.4.0') {
            return "Uninstall Pelican Provisioner before installing this zip. Uninstall drops the quota and ban tables. The new install starts empty.\n";
        }

        return "Update from the plugin list. Overrides, bans, and settings are kept.\n";
    }

    public static function updateUrl(): string
    {
        return self::REPOSITORY.'/releases/latest/download/update.json';
    }
}
