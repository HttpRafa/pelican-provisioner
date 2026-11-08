<?php

declare(strict_types=1);

namespace PelicanProvisioner\Services;

use App\Models\Egg;
use Illuminate\Database\Eloquent\Builder;
use PelicanProvisioner\Domain\AccessDecision;
use PelicanProvisioner\Domain\Grant;
use PelicanProvisioner\Domain\GrantResolver;
use PelicanProvisioner\Domain\QuotaAllowlist;
use PelicanProvisioner\Domain\QuotaPolicy;
use PelicanProvisioner\Domain\QuotaViolationException;
use PelicanProvisioner\Domain\ResourceAmounts;
use PelicanProvisioner\Models\QuotaBan;
use PelicanProvisioner\Models\ResourceQuota;

final class QuotaAccess
{
    /** @var array<int, AccessDecision> */
    private array $decisions = [];

    public function __construct(
        private readonly UsageQuery $usage,
        private readonly QuotaPolicy $policy,
        private readonly SettingsRepository $settings,
        private readonly QuotaAllowlist $allowlist,
    ) {}

    public function decision(int $userId, bool $lock = false, bool $fresh = false): AccessDecision
    {
        if (!$fresh && !$lock && array_key_exists($userId, $this->decisions)) {
            return $this->decisions[$userId];
        }

        $decision = GrantResolver::decide(
            $this->isBanned($userId, $lock, $fresh),
            $this->override($userId, $lock, $fresh),
            $this->settings->current()->defaultQuota,
        );

        if (!$lock) {
            $this->decisions[$userId] = $decision;
        }

        return $decision;
    }

    public function grant(int $userId): ?Grant
    {
        return $this->decision($userId)->grant;
    }

    public function canOpen(int $userId): bool
    {
        return $this->decision($userId)->allowed();
    }

    public function canSpendMinimum(int $userId): bool
    {
        $grant = $this->grant($userId);

        if (!$grant instanceof Grant) {
            return false;
        }

        try {
            $this->policy->assertCanCreate(
                $grant->quota,
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
        $grant = $this->grant($userId);
        $settings = $this->settings->current();

        if (!$grant instanceof Grant || !$settings->createUsable) {
            return false;
        }

        $choice = $this->allowlist->eggs(
            $settings->allowAllEggs,
            $settings->allowedEggIds,
            $grant->eggMode,
            $grant->allowedEggIds,
            $grant->allowAllEggs,
        );

        if ($choice->allowAll) {
            return Egg::query()->whereNotIn('id', $choice->excludedIds)->exists();
        }

        return $choice->ids !== [] && Egg::query()->whereIn('id', $choice->ids)->exists();
    }

    private function isBanned(int $userId, bool $lock, bool $fresh): bool
    {
        $query = QuotaBan::query()->where('user_id', $userId)->select(['id']);
        $this->readConsistently($query, $lock, $fresh);

        return $query->first() !== null;
    }

    private function override(int $userId, bool $lock, bool $fresh): ?Grant
    {
        $query = ResourceQuota::query()
            ->where('user_id', $userId)
            ->select(['id', 'user_id', 'cpu', 'memory', 'disk', 'server_limit', 'egg_mode', 'node_mode', 'allow_all_eggs', 'allowed_egg_ids', 'allowed_node_ids']);
        $this->readConsistently($query, $lock, $fresh);

        $quota = $query->first();

        if (!$quota instanceof ResourceQuota) {
            return null;
        }

        return Grant::override(
            $quota->toQuota(),
            $quota->egg_mode,
            $quota->allowed_egg_ids,
            (bool) $quota->allow_all_eggs,
            $quota->node_mode,
            $quota->allowed_node_ids,
        );
    }

    /** Provisioning has to see a ban or override that was just saved, including when a replica is behind. */
    private function readConsistently(Builder $query, bool $lock, bool $fresh): void
    {
        if ($lock || $fresh) {
            $query->useWritePdo();
        }

        if ($lock) {
            $query->lockForUpdate();
        }
    }
}
