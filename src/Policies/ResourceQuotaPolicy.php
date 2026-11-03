<?php

declare(strict_types=1);

namespace PelicanProvisioner\Policies;

use App\Models\User;
use App\Policies\DefaultAdminPolicies;
use Illuminate\Database\Eloquent\Model;
use PelicanProvisioner\Models\ResourceQuota;

/**
 * Follows the panel's user policy: a normal admin cannot change anything that belongs to a root admin.
 */
class ResourceQuotaPolicy
{
    use DefaultAdminPolicies {
        update as defaultUpdate;
        delete as defaultDelete;
    }

    protected string $modelName = 'provisionerQuota';

    public function update(User $user, Model $model): bool
    {
        return $this->canTargetOwner($user, $model) && $this->defaultUpdate($user, $model);
    }

    public function delete(User $user, Model $model): bool
    {
        return $this->canTargetOwner($user, $model) && $this->defaultDelete($user, $model);
    }

    private function canTargetOwner(User $user, Model $model): bool
    {
        return $model instanceof ResourceQuota && $model->user instanceof User && $user->canTarget($model->user);
    }
}
