<?php

declare(strict_types=1);

namespace PelicanProvisioner\Filament\App\Pages;

use App\Filament\Components\Forms\Fields\StartupVariable;
use App\Filament\Server\Pages\Console;
use App\Models\Egg;
use App\Models\EggVariable;
use App\Models\Node;
use App\Models\User;
use App\Services\Deployment\FindViableNodesService;
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
use PelicanProvisioner\Domain\AccessMode;
use PelicanProvisioner\Domain\InvalidResourceAmountException;
use PelicanProvisioner\Domain\PositiveId;
use PelicanProvisioner\Domain\ProvisioningException;
use PelicanProvisioner\Domain\QuotaAllowlist;
use PelicanProvisioner\Domain\QuotaPolicy;
use PelicanProvisioner\Domain\ResourceAmountParser;
use PelicanProvisioner\Domain\ResourceAmounts;
use PelicanProvisioner\Filament\App\Widgets\QuotaOverview;
use PelicanProvisioner\Models\ResourceQuota;
use PelicanProvisioner\Services\QuotaAccess;
use PelicanProvisioner\Services\ServerProvisioner;
use PelicanProvisioner\Services\SettingsRepository;
use PelicanProvisioner\Services\UsageQuery;
use Throwable;

/**
 * $data has to be a public Livewire property or the wizard submits empty fields. The create button is type=button with wire:click because the wizard action is not inside a form, so a submit button does not call save. Because the client can change anything in $data, a startup row keeps only its variable name there. Its label, rules, and whether it can be edited come from the database.
 *
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

    /** @var array<int, array<string, EggVariable>> */
    private array $trustedVariableCache = [];

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
                            Select::make('node_id')
                                ->label(trans('pelican-provisioner::strings.node'))
                                ->prefixIcon('tabler-server-2')
                                ->options(fn (Get $get): array => $this->nodeOptions($get('cpu'), $get('memory'), $get('disk')))
                                ->visible(fn (): bool => $this->restrictsNodes())
                                ->required(fn (): bool => $this->restrictsNodes())
                                ->searchable()
                                ->columnSpanFull(),
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
                                    StartupVariable::make('variable_value')
                                        ->variableName(fn (Get $get): ?string => $this->trustedVariable($get)?->name)
                                        ->variableDesc(fn (Get $get): ?string => $this->trustedVariable($get)?->description)
                                        ->variableEnv(fn (Get $get): ?string => $this->trustedVariable($get)?->env_variable)
                                        ->variableDefault(fn (Get $get): ?string => $this->trustedVariable($get)?->default_value)
                                        ->variableRules(fn (Get $get): array => $this->trustedVariable($get)?->rules ?? [])
                                        ->disabled(fn (Get $get): bool => !$this->trustedVariable($get)?->user_editable)
                                        // The field's number cast turns what was typed into a float, which cannot hold a long ID exactly.
                                        ->dehydrateStateUsing(fn (StartupVariable $component): mixed => $component->getRawState()),
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
                nodeId: $data['node_id'] ?? null,
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
            ->live(onBlur: true)
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
        $quota = $this->quota();
        $choice = (new QuotaAllowlist())->eggs(
            $settings->allowAllEggs,
            $settings->allowedEggIds,
            $quota?->egg_mode,
            $quota?->allowed_egg_ids,
            (bool) $quota?->allow_all_eggs,
        );

        if (!$settings->createUsable || (!$choice->allowAll && $choice->ids === [])) {
            $this->eggOptionCache = [];

            return;
        }

        $query = Egg::query()->orderBy('name');

        if ($choice->allowAll && $choice->excludedIds !== []) {
            $query->whereNotIn('id', $choice->excludedIds);
        }

        if (!$choice->allowAll) {
            $query->whereIn('id', $choice->ids);
        }

        $eggs = $query->get(['id', 'name', 'description']);
        $options = [];

        foreach ($eggs as $egg) {
            $options[$egg->id] = (string) $egg->name;
            $this->eggDescriptions[(int) $egg->id] = is_string($egg->description) ? $egg->description : null;
        }

        $this->eggOptionCache = $options;
    }

    /** @return list<array{env_variable: string, variable_value: string|null}> */
    private function variableState(mixed $eggId): array
    {
        return array_values(array_map(
            fn (EggVariable $variable): array => [
                'env_variable' => $variable->env_variable,
                'variable_value' => $variable->default_value,
            ],
            $this->trustedVariables($eggId),
        ));
    }

    private function trustedVariable(Get $get): ?EggVariable
    {
        $name = $get('env_variable');

        return is_string($name) ? ($this->trustedVariables($get('../../egg_id'))[$name] ?? null) : null;
    }

    /** @return array<string, EggVariable> The variables this user may see, for an egg this user may choose. */
    private function trustedVariables(mixed $eggId): array
    {
        $id = PositiveId::parse($eggId);

        if ($id === null || !array_key_exists($id, $this->eggOptions())) {
            return [];
        }

        return $this->trustedVariableCache[$id] ??= EggVariable::query()
            ->where('egg_id', $id)
            ->where('user_viewable', true)
            ->orderBy('sort')
            ->get(['id', 'egg_id', 'name', 'description', 'env_variable', 'default_value', 'user_viewable', 'user_editable', 'rules', 'sort'])
            ->keyBy('env_variable')
            ->all();
    }

    /** @return array<int, string> */
    private function nodeOptions(mixed $cpu, mixed $memory, mixed $disk): array
    {
        $quota = $this->quota();
        $settings = app(SettingsRepository::class)->current();

        if (!$quota instanceof ResourceQuota || !$settings->createUsable || !$this->restrictsNodes()) {
            return [];
        }

        try {
            $requested = new ResourceAmounts(
                ResourceAmountParser::parse($cpu),
                ResourceAmountParser::parse($memory),
                ResourceAmountParser::parse($disk),
            );
        } catch (InvalidResourceAmountException) {
            return [];
        }

        $tagged = app(FindViableNodesService::class)->handle(
            $requested->memory,
            $requested->disk,
            $requested->cpu,
            $settings->tags,
        )->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
        $selected = QuotaAllowlist::ids($quota->allowed_node_ids);
        $mode = AccessMode::parse($quota->node_mode);

        if (($mode === AccessMode::Additive || $mode === AccessMode::Overwrite) && $selected !== []) {
            $selected = Node::query()
                ->whereIn('id', $selected)
                ->get()
                ->filter(fn (Node $node): bool => $node->isViable($requested->memory, $requested->disk, $requested->cpu))
                ->map(fn (Node $node): int => (int) $node->id)
                ->all();
        }

        $allowed = (new QuotaAllowlist())->nodes($tagged, $quota->node_mode, $selected);

        if ($allowed === []) {
            return [];
        }

        return Node::query()->whereIn('id', $allowed)->orderBy('name')->pluck('name', 'id')->all();
    }

    private function restrictsNodes(): bool
    {
        $quota = $this->quota();

        return $quota instanceof ResourceQuota && AccessMode::parse($quota->node_mode) !== null;
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
