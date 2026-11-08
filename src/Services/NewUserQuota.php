<?php

declare(strict_types=1);

namespace PelicanProvisioner\Services;

use PelicanProvisioner\Domain\DefaultQuota;
use PelicanProvisioner\Domain\InvalidResourceAmountException;
use PelicanProvisioner\Domain\Quota;
use PelicanProvisioner\Models\ResourceQuota;

/**
 * Creates the quota for one new account from the saved default. Existing rows are left alone, including users created before a default was saved.
 */
final class NewUserQuota
{
    public function assign(int $userId): void
    {
        if ($userId <= 0) {
            return;
        }

        $config = config('pelican-provisioner');

        if (!is_array($config)) {
            return;
        }

        try {
            $quota = DefaultQuota::parse($config);
        } catch (InvalidResourceAmountException) {
            return;
        }

        if (!$quota instanceof Quota) {
            return;
        }

        ResourceQuota::query()->firstOrCreate(
            ['user_id' => $userId],
            [
                'cpu' => $quota->cpu,
                'memory' => $quota->memory,
                'disk' => $quota->disk,
                'server_limit' => $quota->serverLimit,
            ],
        );
    }
}
