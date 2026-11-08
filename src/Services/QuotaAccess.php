<?php

declare(strict_types=1);

namespace PelicanProvisioner\Services;

use PelicanProvisioner\Domain\QuotaPolicy;
use PelicanProvisioner\Domain\QuotaViolationException;
use PelicanProvisioner\Domain\ResourceAmounts;
use PelicanProvisioner\Models\ResourceQuota;

final class QuotaAccess
{
    /** @var array<int, ResourceQuota|null> */
    private array $quotas = [];

    public function __construct(
        private readonly UsageQuery $usage,
        private readonly QuotaPolicy $policy,
    ) {}

    public function quotaFor(int $userId): ?ResourceQuota
    {
        if (!array_key_exists($userId, $this->quotas)) {
            $this->quotas[$userId] = ResourceQuota::query()
                ->where('user_id', $userId)
                ->first(['id', 'user_id', 'cpu', 'memory', 'disk', 'server_limit', 'egg_mode', 'node_mode', 'allow_all_eggs', 'allowed_egg_ids', 'allowed_node_ids']);
        }

        return $this->quotas[$userId];
    }

    public function canOpen(int $userId): bool
    {
        return $this->quotaFor($userId) !== null;
    }

    public function canSpendMinimum(int $userId): bool
    {
        $quota = $this->quotaFor($userId);

        if ($quota === null) {
            return false;
        }

        try {
            $this->policy->assertCanCreate(
                $quota->toQuota(),
                $this->usage->forOwner($userId),
                new ResourceAmounts(1, 1, 1),
            );

            return true;
        } catch (QuotaViolationException) {
            return false;
        }
    }
}
