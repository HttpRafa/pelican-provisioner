<?php

declare(strict_types=1);

namespace PelicanProvisioner\Policies;

use App\Policies\DefaultAdminPolicies;

class ResourceQuotaPolicy
{
    use DefaultAdminPolicies;

    protected string $modelName = 'provisionerQuota';
}
