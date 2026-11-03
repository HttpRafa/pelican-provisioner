<?php

declare(strict_types=1);

namespace PelicanProvisioner\Providers;

use App\Enums\HeaderActionPosition;
use App\Enums\HeaderWidgetPosition;
use App\Filament\Admin\Resources\Users\UserResource;
use App\Filament\App\Resources\Servers\Pages\ListServers;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use PelicanProvisioner\Filament\Admin\Resources\Users\RelationManagers\ResourceQuotaRelationManager;
use PelicanProvisioner\Filament\App\Widgets\QuotaOverview;
use PelicanProvisioner\Filament\Components\Actions\CreateServerAction;
use PelicanProvisioner\Models\ResourceQuota;
use PelicanProvisioner\Policies\ResourceQuotaPolicy;
use PelicanProvisioner\Services\QuotaAccess;
use PelicanProvisioner\Services\UsageQuery;

/**
 * Usage and access are scoped so Octane does not keep them for the next request. The policy is registered here because the panel does not discover plugin policies on its own.
 */
class PelicanProvisionerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(UsageQuery::class);
        $this->app->scoped(QuotaAccess::class);

        Gate::policy(ResourceQuota::class, ResourceQuotaPolicy::class);

        UserResource::registerCustomRelations(ResourceQuotaRelationManager::class);
        ListServers::registerCustomHeaderWidgets(HeaderWidgetPosition::Before, QuotaOverview::class);
        ListServers::registerCustomHeaderActions(HeaderActionPosition::Before, CreateServerAction::make());
        Role::registerCustomDefaultPermissions('provisionerQuota');
        Role::registerCustomModelIcon('provisionerQuota', 'tabler-cube-plus');
    }

    public function boot(): void
    {
        User::resolveRelationUsing(
            'provisionerQuota',
            fn (User $user) => $user->hasOne(ResourceQuota::class, 'user_id'),
        );
    }
}
