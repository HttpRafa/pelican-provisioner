<?php

declare(strict_types=1);

namespace PelicanProvisioner\Services;

use App\Models\Egg;
use PelicanProvisioner\Domain\QuotaAllowlist;
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
        private readonly SettingsRepository $settings,
        private readonly QuotaAllowlist $allowlist,
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

    /** Uses the same rules as the egg list on the create form, so the button is not offered when that list would be empty. */
    public function canChooseAnEgg(int $userId): bool
    {
        $quota = $this->quotaFor($userId);
        $settings = $this->settings->current();

        if ($quota === null || !$settings->createUsable) {
            return false;
        }

        $choice = $this->allowlist->eggs(
            $settings->allowAllEggs,
            $settings->allowedEggIds,
            $quota->egg_mode,
            $quota->allowed_egg_ids,
            (bool) $quota->allow_all_eggs,
        );

        if ($choice->allowAll) {
            return Egg::query()->whereNotIn('id', $choice->excludedIds)->exists();
        }

        return $choice->ids !== [] && Egg::query()->whereIn('id', $choice->ids)->exists();
    }
}
