<?php

declare(strict_types=1);

namespace PelicanProvisioner\Filament\App\Pages;

use App\Filament\Components\Forms\Fields\StartupVariable;
use App\Filament\Server\Pages\Console;
use App\Models\Egg;
use App\Models\EggVariable;
use App\Models\User;
use App\Services\Servers\RandomWordService;
use BackedEnum;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Concerns\InteractsWithFormActions;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Filament\Support\Facades\FilamentView;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\HtmlString;
use PelicanProvisioner\Domain\ProvisioningException;
use PelicanProvisioner\Domain\QuotaPolicy;
use PelicanProvisioner\Domain\ResourceAmounts;
use PelicanProvisioner\Filament\App\Widgets\QuotaOverview;
use PelicanProvisioner\Models\ResourceQuota;
use PelicanProvisioner\Services\QuotaAccess;
use PelicanProvisioner\Services\ServerProvisioner;
use PelicanProvisioner\Services\SettingsRepository;
use PelicanProvisioner\Services\UsageQuery;
use Throwable;

/**
 * @property Schema $form
 */
class CreateServerPage extends Page
{
    use InteractsWithFormActions;
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = 'tabler-cube-plus';

    protected static ?string $slug = 'create-server';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.server.pages.server-form-page';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    /** @var array<int|string, string>|null */
    private ?array $eggOptionCache = null;

    /** @var array<int, string|null> */
    private array $eggDescriptions = [];

    public function getTitle(): string
    {
        return trans('pelican-provisioner::strings.create_server');
    }

    public static function canAccess(): bool
    {
        $actor = user();

        return $actor instanceof User && app(QuotaAccess::class)->canOpen((int) $actor->id);
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $name = '';

        try {
            $name = app(RandomWordService::class)->word();
        } catch (Throwable) {
            $name = '';
        }

        $this->form->fill([
            'name' => $name,
            'variables' => [],
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Wizard::make([
                    Step::make('details')
                        ->label(trans('pelican-provisioner::strings.steps.details'))
                        ->description(trans('pelican-provisioner::strings.steps.details_help'))
                        ->icon('tabler-server')
                        ->schema([
                            TextInput::make('name')
                                ->label(trans('pelican-provisioner::strings.name'))
                                ->prefixIcon('tabler-server')
                                ->required()
                                ->maxLength(255)
                                ->columnSpanFull(),
                            Select::make('egg_id')
                                ->label(trans('pelican-provisioner::strings.egg'))
                                ->prefixIcon('tabler-egg')
                                ->options(fn (): array => $this->eggOptions())
                                ->placeholder(fn (): string => $this->eggOptions() === []
                                    ? trans('pelican-provisioner::strings.no_eggs')
                                    : trans('pelican-provisioner::strings.select_egg'))
                                ->required()
                                ->searchable()
                                ->live()
                                ->afterStateUpdated(function ($state, Set $set): void {
                                    $set('variables', $this->variableState($state));
                                })
                                ->columnSpanFull(),
                            TextEntry::make('egg_description')
                                ->label(trans('pelican-provisioner::strings.egg_description'))
                                ->state(function (Get $get): ?string {
                                    $eggId = $get('egg_id');

                                    if (!is_numeric($eggId)) {
                                        return null;
                                    }

                                    $description = $this->eggDescriptions[(int) $eggId] ?? null;

                                    return is_string($description) ? $description : null;
                                })
                                ->visible(fn (Get $get): bool => filled($get('egg_id')))
                                ->columnSpanFull(),
                        ]),
                    Step::make('resources')
                        ->label(trans('pelican-provisioner::strings.steps.resources'))
                        ->description(trans('pelican-provisioner::strings.steps.resources_help'))
                        ->icon('tabler-cpu')
                        ->columns(3)
                        ->schema([
                            $this->resourceField('cpu', 'cpu', '%'),
                            $this->resourceField('memory', 'memory', $this->memorySuffix()),
                            $this->resourceField('disk', 'disk', $this->memorySuffix()),
                        ]),
                    Step::make('startup')
                        ->label(trans('pelican-provisioner::strings.steps.startup'))
                        ->description(trans('pelican-provisioner::strings.steps.startup_help'))
                        ->icon('tabler-player-play')
                        ->schema([
                            TextEntry::make('no_variables')
                                ->hiddenLabel()
                                ->state(trans('pelican-provisioner::strings.no_variables'))
                                ->visible(fn (Get $get): bool => filled($get('egg_id')) && $get('variables') === []),
                            Repeater::make('variables')
                                ->label(trans('pelican-provisioner::strings.variables'))
                                ->hidden(fn (Get $get): bool => !filled($get('egg_id')) || $get('variables') === [])
                                ->grid(2)
                                ->columnSpanFull()
                                ->reorderable(false)
                                ->addable(false)
                                ->deletable(false)
                                ->default([])
                                ->schema([
                                    Hidden::make('env_variable'),
                                    Hidden::make('user_editable'),
                                    Hidden::make('user_viewable'),
                                    Hidden::make('rules'),
                                    Hidden::make('name'),
                                    Hidden::make('description'),
                                    Hidden::make('default_value'),
                                    StartupVariable::make('variable_value')->fromForm(),
                                ]),
                        ]),
                ])
                    ->columnSpanFull()
                    ->submitAction(new HtmlString(
                        '<button type="button" wire:click="save" wire:loading.attr="disabled" class="fi-btn fi-btn-size-md fi-color-primary">'.e(trans('pelican-provisioner::strings.create_server')).'</button>',
                    )),
            ]);
    }

    protected function getHeaderWidgets(): array
    {
        return [
            QuotaOverview::class,
        ];
    }

    public function save(): void
    {
        $data = $this->form->getState();

        try {
            $server = app(ServerProvisioner::class)->create(
                name: $data['name'] ?? null,
                eggId: $data['egg_id'] ?? null,
                cpu: $data['cpu'] ?? null,
                memory: $data['memory'] ?? null,
                disk: $data['disk'] ?? null,
                variables: $data['variables'] ?? [],
            );

            $url = Console::getUrl(panel: 'server', tenant: $server);
            $this->redirect($url, navigate: FilamentView::hasSpaMode($url));
        } catch (ProvisioningException $exception) {
            $body = trans('pelican-provisioner::strings.notifications.'.$exception->failure->value);

            if ($exception->failure->value === 'invalid_variables' && filled($exception->detail)) {
                $body .= ' '.$exception->detail;
            }

            Notification::make()
                ->title(trans('pelican-provisioner::strings.notifications.server_creation_failed'))
                ->body($body)
                ->danger()
                ->send();
        } catch (ConnectionException) {
            Notification::make()
                ->title(trans('pelican-provisioner::strings.notifications.server_creation_failed'))
                ->body(trans('pelican-provisioner::strings.notifications.unknown_server_creation_error'))
                ->danger()
                ->send();
        }
    }

    private function resourceField(string $name, string $label, string $suffix): TextInput
    {
        return TextInput::make($name)
            ->label(trans('pelican-provisioner::strings.'.$label))
            ->helperText(trans('pelican-provisioner::strings.resource_help'))
            ->required()
            ->integer()
            ->minValue(fn (): int => $this->bounds($name)['min'])
            ->maxValue(fn (): int => $this->bounds($name)['max'])
            ->suffix($suffix)
            ->hint(fn ($state): string => $this->hint($name, $state));
    }

    /** @return array{min: int, max: int} */
    private function bounds(string $resource): array
    {
        $quota = $this->quota();
        $actor = user();

        if (!$quota instanceof ResourceQuota || !$actor instanceof User) {
            return ['min' => 1, 'max' => 0];
        }

        $usage = app(UsageQuery::class)->forOwner((int) $actor->id);
        $limit = match ($resource) {
            'cpu' => $quota->cpu,
            'memory' => $quota->memory,
            default => $quota->disk,
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
        $maximum = app(QuotaPolicy::class)->maximum((int) $limit, $used, $unlimited);

        if ($maximum === null) {
            return ['min' => 0, 'max' => ResourceAmounts::ABSOLUTE_MAX];
        }

        return ['min' => 1, 'max' => $maximum];
    }

    private function hint(string $resource, mixed $state): string
    {
        $bounds = $this->bounds($resource);

        if ($bounds['min'] === 0 && $bounds['max'] === ResourceAmounts::ABSOLUTE_MAX) {
            return trans('pelican-provisioner::strings.unlimited');
        }

        $typed = is_numeric($state) ? (int) $state : 0;

        return trans('pelican-provisioner::strings.widget.remaining', [
            'amount' => max(0, $bounds['max'] - $typed).($resource === 'cpu' ? '%' : ' '.$this->memorySuffix()),
        ]);
    }

    /** @return array<int|string, string> */
    private function eggOptions(): array
    {
        $this->loadEggs();

        return $this->eggOptionCache ?? [];
    }

    private function loadEggs(): void
    {
        if ($this->eggOptionCache !== null) {
            return;
        }

        $settings = app(SettingsRepository::class)->current();

        if (!$settings->createUsable) {
            $this->eggOptionCache = [];

            return;
        }

        $query = Egg::query()->orderBy('name');

        if ($settings->allowedEggIds !== []) {
            $query->whereIn('id', $settings->allowedEggIds);
        }

        $eggs = $query->get(['id', 'name', 'description']);
        $options = [];

        foreach ($eggs as $egg) {
            $options[$egg->id] = (string) $egg->name;
            $this->eggDescriptions[(int) $egg->id] = is_string($egg->description) ? $egg->description : null;
        }

        $this->eggOptionCache = $options;
    }

    /** @return list<array<string, mixed>> */
    private function variableState(mixed $eggId): array
    {
        if (!is_numeric($eggId)) {
            return [];
        }

        return EggVariable::query()
            ->where('egg_id', (int) $eggId)
            ->where('user_viewable', true)
            ->orderBy('sort')
            ->get(['id', 'egg_id', 'name', 'description', 'env_variable', 'default_value', 'user_viewable', 'user_editable', 'rules', 'sort'])
            ->map(function (EggVariable $variable): array {
                $row = $variable->toArray();
                $row['variable_value'] = $variable->default_value;

                return $row;
            })
            ->values()
            ->all();
    }

    private function quota(): ?ResourceQuota
    {
        $actor = user();

        if (!$actor instanceof User) {
            return null;
        }

        return app(QuotaAccess::class)->quotaFor((int) $actor->id);
    }

    private function memorySuffix(): string
    {
        return config('panel.use_binary_prefix') ? 'MiB' : 'MB';
    }
}
