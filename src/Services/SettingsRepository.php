<?php

declare(strict_types=1);

namespace PelicanProvisioner\Services;

use PelicanProvisioner\Domain\DeploymentSettings;
use PelicanProvisioner\Domain\SettingsSanitizer;

final class SettingsRepository
{
    public function __construct(private readonly SettingsSanitizer $sanitizer) {}

    public function current(): DeploymentSettings
    {
        $config = config('pelican-provisioner');

        if (!is_array($config)) {
            return DeploymentSettings::blocked();
        }

        return $this->sanitizer->fromConfig($config);
    }
}
