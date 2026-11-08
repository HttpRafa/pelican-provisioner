<?php

declare(strict_types=1);

namespace PelicanProvisioner\Policies;

use App\Models\User;
use App\Policies\DefaultAdminPolicies;
use Illuminate\Database\Eloquent\Model;
use PelicanProvisioner\Models\QuotaBan;

/**
 * Follows the panel's user policy: a normal admin cannot change anything that belongs to a root admin.
 */
class QuotaBanPolicy
{
    use DefaultAdminPolicies {
        delete as defaultDelete;
    }

    protected string $modelName = 'provisionerQuotaBan';

    public function delete(User $user, Model $model): bool
    {
        return $this->canTargetOwner($user, $model) && $this->defaultDelete($user, $model);
    }

    private function canTargetOwner(User $user, Model $model): bool
    {
        return $model instanceof QuotaBan && $model->user instanceof User && $user->canTarget($model->user);
    }
}
