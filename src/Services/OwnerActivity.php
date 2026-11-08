<?php

declare(strict_types=1);

namespace PelicanProvisioner\Services;

use App\Facades\Activity;
use App\Models\User;
use Throwable;

/**
 * Writes an activity entry the quota owner can see. The signed-in user is the actor. A failure here is reported and does not undo the quota change.
 */
final class OwnerActivity
{
    /** @param callable(?string): array<string, string> $properties */
    public function write(int $userId, string $event, callable $properties): void
    {
        if (!class_exists(Activity::class) || !class_exists(User::class)) {
            return;
        }

        try {
            $owner = User::query()->find($userId);

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
}
