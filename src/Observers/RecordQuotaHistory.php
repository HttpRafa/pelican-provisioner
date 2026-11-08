<?php

declare(strict_types=1);

namespace PelicanProvisioner\Observers;

use PelicanProvisioner\Domain\AccessMode;
use PelicanProvisioner\Domain\DefaultQuota;
use PelicanProvisioner\Domain\InvalidResourceAmountException;
use PelicanProvisioner\Domain\Quota;
use PelicanProvisioner\Domain\QuotaAllowlist;
use PelicanProvisioner\Domain\QuotaHistory;
use PelicanProvisioner\Models\ResourceQuota;
use PelicanProvisioner\Services\OwnerActivity;

/**
 * Writes activity entries for the quota owner. Limits and egg or node access get separate entries, and a save that changes neither writes nothing. Creating or deleting an override is compared with the saved default, because that is what the owner had before the row and what they return to after it is gone.
 */
class RecordQuotaHistory
{
    public function __construct(private readonly OwnerActivity $activity) {}

    public function created(ResourceQuota $quota): void
    {
        $this->writeLimits($quota, $this->savedDefault(), $this->quota($quota, false), QuotaHistory::EVENT);

        if (AccessMode::parse($quota->egg_mode) !== null || AccessMode::parse($quota->node_mode) !== null) {
            $this->writeAccess($quota);
        }
    }

    public function updated(ResourceQuota $quota): void
    {
        if ($quota->wasChanged(QuotaHistory::LIMIT_FIELDS)) {
            $this->writeLimits($quota, $this->quota($quota, true), $this->quota($quota, false), QuotaHistory::EVENT);
        }

        if ($quota->wasChanged(QuotaHistory::ACCESS_FIELDS)) {
            $this->writeAccess($quota);
        }
    }

    public function deleted(ResourceQuota $quota): void
    {
        $this->writeLimits($quota, $this->quota($quota, false), $this->savedDefault(), QuotaHistory::REMOVED_EVENT);
    }

    private function writeLimits(ResourceQuota $quota, ?Quota $before, ?Quota $after, string $event): void
    {
        $unlimited = trans('pelican-provisioner::strings.history_unlimited');

        $this->activity->write(
            (int) $quota->user_id,
            $event,
            fn (?string $actor): array => QuotaHistory::properties($actor, $before, $after, $unlimited),
        );
    }

    private function writeAccess(ResourceQuota $quota): void
    {
        $eggs = $this->describe($quota->egg_mode, $quota->allowed_egg_ids, (bool) $quota->allow_all_eggs);
        $nodes = $this->describe($quota->node_mode, $quota->allowed_node_ids, false);

        $this->activity->write(
            (int) $quota->user_id,
            QuotaHistory::ACCESS_EVENT,
            fn (?string $actor): array => QuotaHistory::access($actor, $eggs, $nodes),
        );
    }

    private function describe(mixed $mode, mixed $ids, bool $everyEgg): string
    {
        $parsed = AccessMode::parse($mode);

        if ($parsed === null) {
            return trans('pelican-provisioner::strings.admin.mode_inherit');
        }

        if ($parsed === AccessMode::Overwrite && $everyEgg) {
            return trans('pelican-provisioner::strings.admin.all_eggs');
        }

        return trans('pelican-provisioner::strings.admin.mode_'.$parsed->value).' ('.count(QuotaAllowlist::ids($ids)).')';
    }

    private function quota(ResourceQuota $quota, bool $original): ?Quota
    {
        $cpu = $original ? $quota->getOriginal('cpu') : $quota->cpu;
        $memory = $original ? $quota->getOriginal('memory') : $quota->memory;
        $disk = $original ? $quota->getOriginal('disk') : $quota->disk;
        $servers = $original ? $quota->getOriginal('server_limit') : $quota->server_limit;

        if ($cpu === null || $memory === null || $disk === null) {
            return null;
        }

        return new Quota((int) $cpu, (int) $memory, (int) $disk, $servers === null ? null : (int) $servers);
    }

    private function savedDefault(): ?Quota
    {
        $config = config('pelican-provisioner');

        if (!is_array($config)) {
            return null;
        }

        try {
            return DefaultQuota::parse($config);
        } catch (InvalidResourceAmountException) {
            return null;
        }
    }
}
