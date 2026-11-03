<?php

declare(strict_types=1);

namespace PelicanProvisioner\Filament\Admin\Resources\ResourceQuotas;

use App\Enums\CustomizationKey;
use App\Filament\Admin\Resources\Users\Pages\EditUser;
use App\Models\Role;
use App\Models\User;
use Closure;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\PaginationMode;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use PelicanProvisioner\Domain\ResourceAmounts;
use PelicanProvisioner\Filament\Admin\Resources\ResourceQuotas\Pages\ManageResourceQuotas;
use PelicanProvisioner\Models\ResourceQuota;
use PelicanProvisioner\Services\UsageQuery;
use Throwable;

class ResourceQuotaResource extends Resource
{
    protected static ?string $model = ResourceQuota::class;

    protected static string|\BackedEnum|null $navigationIcon = 'tabler-cube-plus';

    public static function getNavigationLabel(): string
    {
        return trans_choice('pelican-provisioner::strings.user_resource_limits', 2);
    }

    public static function getModelLabel(): string
    {
        return trans_choice('pelican-provisioner::strings.user_resource_limits', 1);
    }

    public static function getPluralModelLabel(): string
    {
        return trans_choice('pelican-provisioner::strings.user_resource_limits', 2);
    }

    public static function getNavigationGroup(): ?string
    {
        if (user()?->getCustomization(CustomizationKey::TopNavigation) === 'topbar') {
            return null;
        }

        return trans('admin/dashboard.user');
    }

    public static function getNavigationBadge(): ?string
    {
        try {
            $count = Cache::remember(
                ResourceQuota::COUNT_CACHE_KEY,
                60,
                static fn (): int => ResourceQuota::query()->count(),
            );
        } catch (Throwable) {
            return null;
        }

        return $count > 0 ? (string) $count : null;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->select(['id', 'user_id', 'cpu', 'memory', 'disk', 'server_limit', 'created_at', 'updated_at'])
            ->with(['user:id,username,email'])
            ->withCount('ownedServers as servers_used');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('user.username')
                    ->label(trans_choice('pelican-provisioner::strings.user', 1))
                    ->icon('tabler-user')
                    ->searchable(query: function (Builder $query, string $search): void {
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
                    })
                    ->url(fn (ResourceQuota $quota): ?string => $quota->user !== null && user()?->can('update', $quota->user)
                        ? EditUser::getUrl(['record' => $quota->user])
                        : null),
                TextColumn::make('cpu')
                    ->label(trans('pelican-provisioner::strings.cpu'))
                    ->badge()
                    ->formatStateUsing(fn (int $state): string => self::formatAmount($state, '%')),
                TextColumn::make('memory')
                    ->label(trans('pelican-provisioner::strings.memory'))
                    ->badge()
                    ->formatStateUsing(fn (int $state): string => self::formatAmount($state, self::suffix())),
                TextColumn::make('disk')
                    ->label(trans('pelican-provisioner::strings.disk'))
                    ->badge()
                    ->formatStateUsing(fn (int $state): string => self::formatAmount($state, self::suffix())),
                TextColumn::make('server_limit')
                    ->label(trans('pelican-provisioner::strings.server_limit'))
                    ->badge()
                    ->placeholder(trans('pelican-provisioner::strings.no_limit'))
                    ->formatStateUsing(fn (?int $state): string => $state === null || $state === 0
                        ? trans('pelican-provisioner::strings.no_limit')
                        : (string) $state),
                TextColumn::make('servers_used')
                    ->label(trans('pelican-provisioner::strings.servers'))
                    ->badge(),
            ])
            ->recordActions([
                ViewAction::make()
                    ->hidden(fn (ResourceQuota $record): bool => static::getEditAuthorizationResponse($record)->allowed()),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                CreateAction::make()->createAnother(false),
            ])
            ->emptyStateIcon('tabler-cube-plus')
            ->emptyStateDescription(trans('pelican-provisioner::strings.admin.empty'))
            ->defaultSort('id', 'desc')
            ->paginationMode(PaginationMode::Simple)
            ->paginated([10, 25, 50])
            ->deferLoading();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(trans_choice('pelican-provisioner::strings.user_resource_limits', 1))
                    // The modal schema is two columns. Without a full span the card sits in the left half and the right half is empty. trans_choice picks "Quota"; trans() would print the raw "Quota|Quotas" string.
                    ->columnSpanFull()
                    ->columns(2)
                    ->schema([
                        Select::make('user_id')
                            ->label(trans_choice('pelican-provisioner::strings.user', 1))
                            ->required()
                            ->prefixIcon('tabler-user')
                            ->relationship(
                                name: 'user',
                                titleAttribute: 'username',
                                modifyQueryUsing: fn (Builder $query): Builder => self::selectableUsers($query)->select(['id', 'username', 'email'])->orderBy('username'),
                            )
                            ->searchable(self::canSeeEmails() ? ['username', 'email'] : ['username'])
                            ->searchDebounce(300)
                            ->getOptionLabelFromRecordUsing(fn (User $user): string => self::canSeeEmails() ? $user->username.' ('.$user->email.')' : (string) $user->username)
                            ->rules([
                                fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                                    $target = User::query()->find($value);

                                    if (!$target instanceof User || !user()?->canTarget($target)) {
                                        $fail(trans('pelican-provisioner::strings.admin.cannot_target'));
                                    }
                                },
                            ])
                            ->unique(table: 'provisioner_quotas', column: 'user_id')
                            ->dehydrated(fn (?ResourceQuota $record): bool => $record === null)
                            ->disabled(fn (?ResourceQuota $record): bool => $record !== null)
                            ->columnSpanFull(),
                        self::quotaField('cpu', '%'),
                        self::quotaField('memory', self::suffix()),
                        self::quotaField('disk', self::suffix()),
                        TextInput::make('server_limit')
                            ->label(trans('pelican-provisioner::strings.server_limit'))
                            ->helperText(trans('pelican-provisioner::strings.hint_server_limit'))
                            ->integer()
                            ->required()
                            ->minValue(0)
                            ->maxValue(ResourceAmounts::ABSOLUTE_MAX),
                        TextEntry::make('current_usage')
                            ->label(trans('pelican-provisioner::strings.admin.usage_heading'))
                            ->visible(fn (?ResourceQuota $record): bool => $record !== null)
                            ->state(function (?ResourceQuota $record): string {
                                if (!$record instanceof ResourceQuota) {
                                    return '';
                                }

                                $usage = app(UsageQuery::class)->forOwner((int) $record->user_id);
                                $suffix = self::suffix();

                                return trans('pelican-provisioner::strings.admin.usage', [
                                    'cpu' => $usage->cpu,
                                    'memory' => $usage->memory,
                                    'disk' => $usage->disk,
                                    'suffix' => $suffix,
                                    'servers' => $usage->servers,
                                ]);
                            })
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('user.username')
                ->label(trans_choice('pelican-provisioner::strings.user', 1)),
            TextEntry::make('cpu')
                ->label(trans('pelican-provisioner::strings.cpu'))
                ->formatStateUsing(fn (int $state): string => self::formatAmount($state, '%')),
            TextEntry::make('memory')
                ->label(trans('pelican-provisioner::strings.memory'))
                ->formatStateUsing(fn (int $state): string => self::formatAmount($state, self::suffix())),
            TextEntry::make('disk')
                ->label(trans('pelican-provisioner::strings.disk'))
                ->formatStateUsing(fn (int $state): string => self::formatAmount($state, self::suffix())),
            TextEntry::make('server_limit')
                ->label(trans('pelican-provisioner::strings.server_limit'))
                ->placeholder(trans('pelican-provisioner::strings.no_limit')),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageResourceQuotas::route('/'),
        ];
    }

    /** The rest of the panel does not let normal admins target root admins, so they cannot hand them a quota either. */
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

    private static function quotaField(string $name, string $suffix): TextInput
    {
        return TextInput::make($name)
            ->label(trans('pelican-provisioner::strings.'.$name))
            ->helperText(trans('pelican-provisioner::strings.hint_unlimited'))
            ->required()
            ->integer()
            ->minValue(0)
            ->maxValue(ResourceAmounts::ABSOLUTE_MAX)
            ->suffix($suffix);
    }

    private static function formatAmount(int $value, string $suffix): string
    {
        return $value > 0 ? $value.$suffix : trans('pelican-provisioner::strings.unlimited');
    }

    private static function suffix(): string
    {
        return config('panel.use_binary_prefix') ? ' MiB' : ' MB';
    }
}
