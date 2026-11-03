<?php

declare(strict_types=1);

namespace PelicanProvisioner\Filament\Server\Pages;

use App\Filament\Server\Pages\ServerFormPage;
use App\Models\Server;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Filament\Support\Facades\FilamentView;
use Illuminate\Http\Client\ConnectionException;
use PelicanProvisioner\Domain\ProvisioningException;
use PelicanProvisioner\Domain\QuotaPolicy;
use PelicanProvisioner\Domain\ResourceAmounts;
use PelicanProvisioner\Filament\App\Widgets\QuotaOverview;
use PelicanProvisioner\Models\ResourceQuota;
use PelicanProvisioner\Services\QuotaAccess;
use PelicanProvisioner\Services\ServerProvisioner;
use PelicanProvisioner\Services\SettingsRepository;
use PelicanProvisioner\Services\UsageQuery;

class ServerResourcePage extends ServerFormPage
{
    protected static string|\BackedEnum|null $navigationIcon = 'tabler-adjustments';

    protected static ?int $navigationSort = 20;

    public static function getNavigationLabel(): string
    {
        return trans('pelican-provisioner::strings.resource_page');
    }

    public function getTitle(): string
    {
        return trans('pelican-provisioner::strings.resource_page');
    }

    public static function canAccess(): bool
    {
        $actor = user();
        $server = Filament::getTenant();

        if (!$actor instanceof User || !$server instanceof Server || (int) $actor->id !== (int) $server->owner_id) {
            return false;
        }

        if (!app(SettingsRepository::class)->current()->usersCanUpdate) {
            return false;
        }

        if (!app(QuotaAccess::class)->canOpen((int) $server->owner_id)) {
            return false;
        }

        return parent::canAccess();
    }

    public function form(Schema $schema): Schema
    {
        return parent::form($schema)
            ->columns([
                'default' => 1,
                'lg' => 3,
            ])
            ->components([
                $this->resourceField('cpu', '%'),
                $this->resourceField('memory', $this->memorySuffix()),
                $this->resourceField('disk', $this->memorySuffix()),
            ]);
    }

    protected function getHeaderWidgets(): array
    {
        return [
            QuotaOverview::class,
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')
                ->label(trans('filament-panels::resources/pages/edit-record.form.actions.save.label'))
                ->action('save')
                ->keyBindings(['mod+s'])
                ->icon('tabler-device-floppy'),
            Action::make('delete_server')
                ->visible(fn (): bool => app(SettingsRepository::class)->current()->usersCanDelete)
                ->authorize(function (): bool {
                    $actor = user();
                    $server = $this->getRecord();

                    return $actor instanceof User && (int) $actor->id === (int) $server->owner_id;
                })
                ->color('danger')
                ->icon('tabler-trash')
                ->label(trans('pelican-provisioner::strings.modals.delete_server'))
                ->requiresConfirmation()
                ->modalHeading(trans('pelican-provisioner::strings.modals.delete_server_confirm'))
                ->modalDescription(trans('pelican-provisioner::strings.modals.delete_server_warning'))
                ->modalSubmitActionLabel(trans('pelican-provisioner::strings.modals.delete_server'))
                ->action(function (ServerProvisioner $provisioner): void {
                    $server = $this->getRecord();

                    try {
                        $provisioner->delete($server);

                        Notification::make()
                            ->title(trans('pelican-provisioner::strings.notifications.server_deleted'))
                            ->body(trans('pelican-provisioner::strings.notifications.server_deleted_success'))
                            ->success()
                            ->send();

                        $this->redirect(Filament::getPanel('app')->getUrl());
                    } catch (ProvisioningException) {
                        Notification::make()
                            ->title(trans('pelican-provisioner::strings.notifications.server_delete_error'))
                            ->body(trans('pelican-provisioner::strings.notifications.delete_failed'))
                            ->danger()
                            ->send();
                    }
                }),
        ];
    }

    public function save(): void
    {
        $data = $this->form->getState();

        try {
            app(ServerProvisioner::class)->updateResources(
                $this->getRecord(),
                $data['cpu'] ?? null,
                $data['memory'] ?? null,
                $data['disk'] ?? null,
            );

            Notification::make()
                ->title(trans('pelican-provisioner::strings.notifications.server_resources_updated'))
                ->body(trans('pelican-provisioner::strings.notifications.might_need_restart'))
                ->success()
                ->send();
        } catch (ProvisioningException $exception) {
            Notification::make()
                ->title(trans('pelican-provisioner::strings.notifications.server_resources_updated'))
                ->body(trans('pelican-provisioner::strings.notifications.'.$exception->failure->value))
                ->danger()
                ->send();

            return;
        } catch (ConnectionException) {
            Notification::make()
                ->title(trans('pelican-provisioner::strings.notifications.server_resources_updated'))
                ->body(trans('pelican-provisioner::strings.notifications.manual_restart_needed'))
                ->warning()
                ->persistent()
                ->send();
        }

        $url = self::getUrl();
        $this->redirect($url, navigate: FilamentView::hasSpaMode($url));
    }

    private function resourceField(string $name, string $suffix): TextInput
    {
        return TextInput::make($name)
            ->label(trans('pelican-provisioner::strings.'.$name))
            ->required()
            ->integer()
            ->live(onBlur: true)
            ->minValue(fn (): int => $this->bounds($name)['min'])
            ->maxValue(fn (): int => $this->bounds($name)['max'])
            ->suffix($suffix)
            ->hint(function ($state) use ($name, $suffix): string {
                $bounds = $this->bounds($name);

                if ($bounds['min'] === 0 && $bounds['max'] === ResourceAmounts::ABSOLUTE_MAX) {
                    return trans('pelican-provisioner::strings.unlimited');
                }

                $typed = is_numeric($state) ? (int) $state : 0;

                return trans('pelican-provisioner::strings.widget.remaining', [
                    'amount' => max(0, $bounds['max'] - $typed).$suffix,
                ]);
            });
    }

    /** @return array{min: int, max: int} */
    private function bounds(string $resource): array
    {
        $server = $this->getRecord();
        $quota = app(QuotaAccess::class)->quotaFor((int) $server->owner_id);

        if (!$quota instanceof ResourceQuota) {
            return ['min' => 1, 'max' => 0];
        }

        $usage = app(UsageQuery::class)->forOwner((int) $server->owner_id, true)->without(
            new ResourceAmounts((int) $server->cpu, (int) $server->memory, (int) $server->disk),
        );
        $limit = match ($resource) {
            'cpu' => (int) $quota->cpu,
            'memory' => (int) $quota->memory,
            default => (int) $quota->disk,
        };
        $used = match ($resource) {
            'cpu' => $usage->cpu,
            'memory' => $usage->memory,
            default => $usage->disk,
        };
        $unlimited = match ($resource) {
            'cpu' => $usage->unlimitedCpuServers,
            'memory' => $usage->unlimitedMemoryServers,
            default => $usage->unlimitedDiskServers,
        };
        $maximum = app(QuotaPolicy::class)->maximum($limit, $used, $unlimited);

        if ($maximum === null) {
            return ['min' => 0, 'max' => ResourceAmounts::ABSOLUTE_MAX];
        }

        return ['min' => 1, 'max' => $maximum];
    }

    private function memorySuffix(): string
    {
        return config('panel.use_binary_prefix') ? 'MiB' : 'MB';
    }
}
