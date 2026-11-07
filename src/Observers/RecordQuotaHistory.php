<?php

declare(strict_types=1);

namespace PelicanProvisioner\Observers;

use App\Facades\Activity;
use App\Models\User;
use PelicanProvisioner\Domain\Quota;
use PelicanProvisioner\Domain\QuotaHistory;
use PelicanProvisioner\Models\ResourceQuota;
use Throwable;

/**
 * Writes one activity entry for the quota owner. The authenticated user is the actor, which is who the owner sees in the activity log.
 */
class RecordQuotaHistory
{
    public function created(ResourceQuota $quota): void
    {
        $this->write($quota, null, $this->quota($quota, false));
    }

    public function updated(ResourceQuota $quota): void
    {
        $this->write($quota, $this->quota($quota, true), $this->quota($quota, false));
    }

    public function deleted(ResourceQuota $quota): void
    {
        $this->write($quota, $this->quota($quota, false), null);
    }

    private function write(ResourceQuota $quota, ?Quota $before, ?Quota $after): void
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
            $entry = Activity::event(QuotaHistory::EVENT)
                ->subject($owner)
                ->property(QuotaHistory::properties(
                    $actor instanceof User ? (string) $actor->username : null,
                    $before,
                    $after,
                    trans('pelican-provisioner::strings.history_unlimited'),
                ));

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
