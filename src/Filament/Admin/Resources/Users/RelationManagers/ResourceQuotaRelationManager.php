<?php

declare(strict_types=1);

namespace PelicanProvisioner\Filament\Admin\Resources\Users\RelationManagers;

use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use PelicanProvisioner\Domain\ResourceAmounts;
use PelicanProvisioner\Filament\Admin\Resources\ResourceQuotas\ResourceQuotaResource;
use PelicanProvisioner\Models\QuotaBan;
use PelicanProvisioner\Models\ResourceQuota;
use PelicanProvisioner\Services\SettingsRepository;
use PelicanProvisioner\Services\UsageQuery;

class ResourceQuotaRelationManager extends RelationManager
{
    protected static string $relationship = 'provisionerQuota';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        if (static::shouldSkipAuthorization()) {
            return true;
        }

        return ResourceQuotaResource::canViewAny();
    }

    public function table(Table $table): Table
    {
        $suffix = config('panel.use_binary_prefix') ? ' MiB' : ' MB';

        return $table
            ->heading(trans_choice('pelican-provisioner::strings.user_resource_limits', 2))
            ->columns([
                TextColumn::make('cpu')
                    ->label(trans('pelican-provisioner::strings.cpu'))
                    ->badge()
                    ->formatStateUsing(fn (int $state): string => $state > 0 ? $state.'%' : trans('pelican-provisioner::strings.unlimited')),
                TextColumn::make('memory')
                    ->label(trans('pelican-provisioner::strings.memory'))
                    ->badge()
                    ->formatStateUsing(fn (int $state): string => $state > 0 ? $state.$suffix : trans('pelican-provisioner::strings.unlimited')),
                TextColumn::make('disk')
                    ->label(trans('pelican-provisioner::strings.disk'))
                    ->badge()
                    ->formatStateUsing(fn (int $state): string => $state > 0 ? $state.$suffix : trans('pelican-provisioner::strings.unlimited')),
                TextColumn::make('server_limit')
                    ->label(trans('pelican-provisioner::strings.server_limit'))
                    ->badge()
                    ->formatStateUsing(fn (?int $state): string => $state === null || $state === 0
                        ? trans('pelican-provisioner::strings.no_limit')
                        : (string) $state),
                TextColumn::make('servers_used')
                    ->label(trans('pelican-provisioner::strings.servers'))
                    ->state(function (ResourceQuota $record): int {
                        return app(UsageQuery::class)->forOwner((int) $record->user_id)->servers;
                    }),
            ])
            ->recordActions([
                ViewAction::make()
                    ->hidden(fn (ResourceQuota $record): bool => static::getEditAuthorizationResponse($record)->allowed()),
                EditAction::make(),
                DeleteAction::make()
                    ->modalDescription(trans('pelican-provisioner::strings.admin.delete_override')),
            ])
            ->toolbarActions([
                CreateAction::make()
                    ->createAnother(false)
                    ->visible(fn (): bool => !$this->hasOverride())
                    ->authorize(fn (): bool => $this->canManageOwner('create', ResourceQuota::class)),
                $this->banAction(),
                $this->liftAction(),
            ])
            ->paginated(false)
            ->emptyStateIcon('tabler-cube-plus')
            ->emptyStateDescription(fn (): string => $this->emptyDescription());
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('cpu')
                ->label(trans('pelican-provisioner::strings.cpu'))
                ->helperText(trans('pelican-provisioner::strings.hint_unlimited'))
                ->required()
                ->integer()
                ->minValue(0)
                ->maxValue(ResourceAmounts::ABSOLUTE_MAX)
                ->rules([ResourceQuotaResource::amountRule()])
                ->suffix('%'),
            TextInput::make('memory')
                ->label(trans('pelican-provisioner::strings.memory'))
                ->helperText(trans('pelican-provisioner::strings.hint_unlimited'))
                ->required()
                ->integer()
                ->minValue(0)
                ->maxValue(ResourceAmounts::ABSOLUTE_MAX)
                ->rules([ResourceQuotaResource::amountRule()])
                ->suffix(config('panel.use_binary_prefix') ? 'MiB' : 'MB'),
            TextInput::make('disk')
                ->label(trans('pelican-provisioner::strings.disk'))
                ->helperText(trans('pelican-provisioner::strings.hint_unlimited'))
                ->required()
                ->integer()
                ->minValue(0)
                ->maxValue(ResourceAmounts::ABSOLUTE_MAX)
                ->rules([ResourceQuotaResource::amountRule()])
                ->suffix(config('panel.use_binary_prefix') ? 'MiB' : 'MB'),
            TextInput::make('server_limit')
                ->label(trans('pelican-provisioner::strings.server_limit'))
                ->helperText(trans('pelican-provisioner::strings.hint_server_limit'))
                ->required()
                ->integer()
                ->minValue(0)
                ->maxValue(ResourceAmounts::ABSOLUTE_MAX)
                ->rules([ResourceQuotaResource::amountRule()]),
            ...ResourceQuotaResource::allowlistFields(),
        ]);
    }

    private function banAction(): Action
    {
        return Action::make('ban')
            ->label(trans('pelican-provisioner::strings.admin.ban'))
            ->icon('tabler-ban')
            ->color('danger')
            ->visible(fn (): bool => $this->ban() === null && $this->canManageOwner('create', QuotaBan::class))
            ->requiresConfirmation()
            ->modalDescription(trans('pelican-provisioner::strings.admin.ban_help'))
            ->action(function (): void {
                $owner = $this->owner();

                if (!$owner instanceof User || !$this->canManageOwner('create', QuotaBan::class)) {
                    return;
                }

                QuotaBan::query()->create(['user_id' => $owner->id]);
            });
    }

    private function liftAction(): Action
    {
        return Action::make('lift')
            ->label(trans('pelican-provisioner::strings.admin.lift_ban'))
            ->icon('tabler-lock-open')
            ->visible(function (): bool {
                $ban = $this->ban();

                return $ban instanceof QuotaBan && (user()?->can('delete', $ban) ?? false);
            })
            ->requiresConfirmation()
            ->modalDescription(trans('pelican-provisioner::strings.admin.lift_ban_help'))
            ->action(function (): void {
                $ban = $this->ban();

                if ($ban instanceof QuotaBan && (user()?->can('delete', $ban) ?? false)) {
                    $ban->delete();
                }
            });
    }

    private function emptyDescription(): string
    {
        if ($this->ban() instanceof QuotaBan) {
            return trans('pelican-provisioner::strings.admin.banned_user');
        }

        $default = app(SettingsRepository::class)->current()->defaultQuota;

        if ($default === null) {
            return trans('pelican-provisioner::strings.admin.empty_user');
        }

        return trans('pelican-provisioner::strings.admin.using_default', [
            'cpu' => $default->cpu,
            'memory' => $default->memory,
            'disk' => $default->disk,
            'servers' => $default->serverCap() ?? trans('pelican-provisioner::strings.no_limit'),
        ]);
    }

    private function hasOverride(): bool
    {
        $owner = $this->owner();

        return $owner instanceof User && ResourceQuota::query()->where('user_id', $owner->id)->exists();
    }

    private function ban(): ?QuotaBan
    {
        $owner = $this->owner();

        if (!$owner instanceof User) {
            return null;
        }

        $ban = QuotaBan::query()->where('user_id', $owner->id)->first();

        return $ban instanceof QuotaBan ? $ban : null;
    }

    private function canManageOwner(string $ability, string $model): bool
    {
        $owner = $this->owner();
        $actor = user();

        return $actor instanceof User
            && $owner instanceof User
            && $actor->can($ability, $model)
            && $actor->canTarget($owner);
    }

    private function owner(): ?User
    {
        $owner = $this->getOwnerRecord();

        return $owner instanceof User ? $owner : null;
    }
}
