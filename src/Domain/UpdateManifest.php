<?php

declare(strict_types=1);

namespace PelicanProvisioner\Domain;

/**
 * The document Pelican fetches from update_url. The wildcard applies to every panel version. The download is the zip the release workflow already builds.
 */
final class UpdateManifest
{
    public const string REPOSITORY = 'https://github.com/HttpRafa/pelican-provisioner';

    /**
     * @return array<string, array{version: string, download_url: string}>
     */
    public static function document(string $version): array
    {
        return [
            '*' => [
                'version' => $version,
                'download_url' => self::REPOSITORY.'/releases/download/v'.$version.'/pelican-provisioner-'.$version.'.zip',
            ],
        ];
    }

    public static function updateUrl(): string
    {
        return self::REPOSITORY.'/releases/latest/download/update.json';
    }
}
