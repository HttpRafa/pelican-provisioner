<?php

declare(strict_types=1);

namespace PelicanProvisioner\Filament\Admin\Resources\QuotaBans;

use App\Enums\CustomizationKey;
use App\Filament\Admin\Resources\Users\Pages\EditUser;
use App\Models\User;
use Closure;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Select;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\PaginationMode;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use PelicanProvisioner\Filament\Admin\AdminAudience;
use PelicanProvisioner\Filament\Admin\Resources\QuotaBans\Pages\ManageQuotaBans;
use PelicanProvisioner\Models\QuotaBan;
use Throwable;

class QuotaBanResource extends Resource
{
    protected static ?string $model = QuotaBan::class;

    protected static string|\BackedEnum|null $navigationIcon = 'tabler-ban';

    public static function getNavigationLabel(): string
    {
        return trans('pelican-provisioner::strings.admin.bans');
    }

    public static function getModelLabel(): string
    {
        return trans('pelican-provisioner::strings.admin.ban');
    }

    public static function getPluralModelLabel(): string
    {
        return trans('pelican-provisioner::strings.admin.bans');
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
                QuotaBan::COUNT_CACHE_KEY,
                60,
                static fn (): int => QuotaBan::query()->count(),
            );
        } catch (Throwable) {
            return null;
        }

        return $count > 0 ? (string) $count : null;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->select(['id', 'user_id', 'created_at', 'updated_at'])
            ->with(['user:id,username,email']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('user.username')
                    ->label(trans_choice('pelican-provisioner::strings.user', 1))
                    ->icon('tabler-user')
                    ->searchable(query: function (Builder $query, string $search): void {
                        AdminAudience::matchingUsers($query, $search);
                    })
                    ->url(fn (QuotaBan $ban): ?string => $ban->user !== null && user()?->can('update', $ban->user)
                        ? EditUser::getUrl(['record' => $ban->user])
                        : null),
                TextColumn::make('created_at')
                    ->label(trans('pelican-provisioner::strings.admin.banned_at'))
                    ->dateTime()
                    ->sortable(),
            ])
            ->recordActions([
                DeleteAction::make()
                    ->label(trans('pelican-provisioner::strings.admin.lift_ban'))
                    ->modalHeading(trans('pelican-provisioner::strings.admin.lift_ban'))
                    ->modalDescription(trans('pelican-provisioner::strings.admin.lift_ban_help')),
            ])
            ->toolbarActions([
                CreateAction::make()
                    ->label(trans('pelican-provisioner::strings.admin.ban'))
                    ->createAnother(false),
            ])
            ->emptyStateIcon('tabler-ban')
            ->emptyStateDescription(trans('pelican-provisioner::strings.admin.ban_empty'))
            ->defaultSort('id', 'desc')
            ->paginationMode(PaginationMode::Simple)
            ->paginated([10, 25, 50])
            ->deferLoading();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(trans('pelican-provisioner::strings.admin.ban'))
                ->description(trans('pelican-provisioner::strings.admin.ban_help'))
                ->columnSpanFull()
                ->schema([
                    Select::make('user_id')
                        ->label(trans_choice('pelican-provisioner::strings.user', 1))
                        ->required()
                        ->prefixIcon('tabler-user')
                        ->relationship(
                            name: 'user',
                            titleAttribute: 'username',
                            modifyQueryUsing: fn (Builder $query): Builder => AdminAudience::selectableUsers($query)->select(['id', 'username', 'email'])->orderBy('username'),
                        )
                        ->searchable(AdminAudience::canSeeEmails() ? ['username', 'email'] : ['username'])
                        ->searchDebounce(300)
                        ->getOptionLabelFromRecordUsing(fn (User $user): string => AdminAudience::canSeeEmails() ? $user->username.' ('.$user->email.')' : (string) $user->username)
                        ->rules([
                            fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                                if (!AdminAudience::accepts($value)) {
                                    $fail(trans('pelican-provisioner::strings.admin.cannot_target'));
                                }
                            },
                        ])
                        ->unique(table: 'provisioner_quota_bans', column: 'user_id')
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageQuotaBans::route('/'),
        ];
    }

    public static function selectableUsers(Builder $query): Builder
    {
        return AdminAudience::selectableUsers($query);
    }
}
