<?php

declare(strict_types=1);

namespace PelicanProvisioner\Observers;

use App\Facades\Activity;
use App\Models\User;
use PelicanProvisioner\Domain\AccessMode;
use PelicanProvisioner\Domain\Quota;
use PelicanProvisioner\Domain\QuotaAllowlist;
use PelicanProvisioner\Domain\QuotaHistory;
use PelicanProvisioner\Models\ResourceQuota;
use Throwable;

/**
 * Writes activity entries for the quota owner. The authenticated user is the actor, which is who the owner sees in the activity log. Limits and egg or node access get separate entries, and a save that changes neither writes nothing.
 */
class RecordQuotaHistory
{
    public function created(ResourceQuota $quota): void
    {
        $this->writeLimits($quota, null, $this->quota($quota, false));

        if (AccessMode::parse($quota->egg_mode) !== null || AccessMode::parse($quota->node_mode) !== null) {
            $this->writeAccess($quota);
        }
    }

    public function updated(ResourceQuota $quota): void
    {
        if ($quota->wasChanged(QuotaHistory::LIMIT_FIELDS)) {
            $this->writeLimits($quota, $this->quota($quota, true), $this->quota($quota, false));
        }

        if ($quota->wasChanged(QuotaHistory::ACCESS_FIELDS)) {
            $this->writeAccess($quota);
        }
    }

    public function deleted(ResourceQuota $quota): void
    {
        $this->writeLimits($quota, $this->quota($quota, false), null);
    }

    private function writeLimits(ResourceQuota $quota, ?Quota $before, ?Quota $after): void
    {
        $unlimited = trans('pelican-provisioner::strings.history_unlimited');

        $this->write($quota, QuotaHistory::EVENT, fn (?string $actor): array => QuotaHistory::properties($actor, $before, $after, $unlimited));
    }

    private function writeAccess(ResourceQuota $quota): void
    {
        $eggs = $this->describe($quota->egg_mode, $quota->allowed_egg_ids, (bool) $quota->allow_all_eggs);
        $nodes = $this->describe($quota->node_mode, $quota->allowed_node_ids, false);

        $this->write($quota, QuotaHistory::ACCESS_EVENT, fn (?string $actor): array => QuotaHistory::access($actor, $eggs, $nodes));
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

    /** @param callable(?string): array<string, string> $properties */
    private function write(ResourceQuota $quota, string $event, callable $properties): void
    {
        if (!class_exists(Activity::class) || !class_exists(User::class)) {
            return;
        }

        try {
            $owner = $quota->relationLoaded('user') ? $quota->user : User::query()->find($quota->user_id);

            if (!$owner instanceof User) {
                return;
            }

            $actor = function_exists('user') ? user() : null;
            $entry = Activity::event($event)
                ->subject($owner)
                ->property($properties($actor instanceof User ? (string) $actor->username : null));

            if ($actor instanceof User) {
                $entry->actor($actor);
            }

            $entry->log();
        } catch (Throwable $exception) {
            report($exception);
        }
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
}
