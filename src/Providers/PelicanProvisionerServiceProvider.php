<?php

declare(strict_types=1);

namespace PelicanProvisioner\Providers;

use App\Enums\HeaderActionPosition;
use App\Enums\HeaderWidgetPosition;
use App\Extensions\BackupAdapter\BackupAdapterService;
use App\Filament\Admin\Resources\Users\UserResource;
use App\Filament\App\Resources\Servers\Pages\ListServers;
use App\Models\Role;
use App\Models\User;
use App\Services\Databases\DatabaseManagementService;
use App\Services\Servers\ServerDeletionService;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use PelicanProvisioner\Filament\Admin\Resources\Users\RelationManagers\ResourceQuotaRelationManager;
use PelicanProvisioner\Filament\App\Widgets\QuotaOverview;
use PelicanProvisioner\Filament\Components\Actions\CreateServerAction;
use PelicanProvisioner\Models\ResourceQuota;
use PelicanProvisioner\Policies\ResourceQuotaPolicy;
use PelicanProvisioner\Services\NewUserQuota;
use PelicanProvisioner\Services\QuotaAccess;
use PelicanProvisioner\Services\ServerProvisioner;
use PelicanProvisioner\Services\StrictDaemonServerRepository;
use PelicanProvisioner\Services\UsageQuery;
use Throwable;

/**
 * Usage and access are scoped so Octane does not keep them for the next request. The policy is registered here because the panel does not discover plugin policies on its own. Only this plugin's provisioner gets the strict node client, so deleting a server from the admin area behaves as it always did.
 */
class PelicanProvisionerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(UsageQuery::class);
        $this->app->scoped(QuotaAccess::class);
        $this->app->when(ServerProvisioner::class)
            ->needs(ServerDeletionService::class)
            ->give(fn (Container $app): ServerDeletionService => new ServerDeletionService(
                $app->make(ConnectionInterface::class),
                $app->make(StrictDaemonServerRepository::class),
                $app->make(DatabaseManagementService::class),
                $app->make(BackupAdapterService::class),
            ));

        Gate::policy(ResourceQuota::class, ResourceQuotaPolicy::class);

        UserResource::registerCustomRelations(ResourceQuotaRelationManager::class);
        ListServers::registerCustomHeaderWidgets(HeaderWidgetPosition::Before, QuotaOverview::class);
        ListServers::registerCustomHeaderActions(HeaderActionPosition::Before, CreateServerAction::make());
        Role::registerCustomDefaultPermissions('provisionerQuota');
        Role::registerCustomModelIcon('provisionerQuota', 'tabler-cube-plus');
    }

    public function boot(): void
    {
        User::created(static function (User $user): void {
            try {
                app(NewUserQuota::class)->assign((int) $user->id);
            } catch (Throwable $exception) {
                report($exception);
            }
        });

        User::resolveRelationUsing(
            'provisionerQuota',
            fn (User $user) => $user->hasOne(ResourceQuota::class, 'user_id'),
        );
    }
}
