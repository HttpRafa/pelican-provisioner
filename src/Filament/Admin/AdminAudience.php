<?php

declare(strict_types=1);

namespace PelicanProvisioner\Filament\Admin;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * The users a normal admin may assign a quota or a ban to. Root administrators are excluded unless the actor is a root administrator, matching the rest of the panel.
 */
final class AdminAudience
{
    public static function selectableUsers(Builder $query): Builder
    {
        if (user()?->isRootAdmin()) {
            return $query;
        }

        return $query->whereDoesntHave('roles', fn (Builder $roles): Builder => $roles->where('name', Role::ROOT_ADMIN));
    }

    public static function canSeeEmails(): bool
    {
        return user()?->can('viewList user') ?? false;
    }

    public static function accepts(mixed $userId): bool
    {
        $target = User::query()->find($userId);

        return $target instanceof User && (user()?->canTarget($target) ?? false);
    }

    public static function matchingUsers(Builder $query, string $search): void
    {
        $search = trim($search);

        if ($search === '' || mb_strlen($search) > 255) {
            return;
        }

        $like = addcslashes($search, '\\%_').'%';
        $withEmail = self::canSeeEmails();

        $query->whereHas('user', function (Builder $user) use ($like, $withEmail): void {
            $user->where(function (Builder $nested) use ($like, $withEmail): void {
                $nested->where('username', 'like', $like);

                if ($withEmail) {
                    $nested->orWhere('email', 'like', $like);
                }
            });
        });
    }
}
