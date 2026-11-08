<?php

declare(strict_types=1);

namespace PelicanProvisioner;

use App\Contracts\Plugins\HasPluginSettings;
use App\Enums\PluginStatus;
use App\Models\Egg;
use App\Models\Plugin as PluginModel;
use App\Traits\EnvironmentWriterTrait;
use Closure;
use Filament\Contracts\Plugin;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Panel;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use PelicanProvisioner\Domain\DefaultQuota;
use PelicanProvisioner\Domain\InvalidResourceAmountException;
use PelicanProvisioner\Domain\InvalidSettingsException;
use PelicanProvisioner\Domain\SettingsSanitizer;
use Throwable;

/**
 * Settings are written to the environment and then reported with a notification. saveSettings must not throw: the panel swallows the exception, so the admin would never learn that nothing was saved.
 */
class PelicanProvisionerPlugin implements HasPluginSettings, Plugin
{
    use EnvironmentWriterTrait;

    public function getId(): string
    {
        return 'pelican-provisioner';
    }

    public function register(Panel $panel): void
    {
        $id = str($panel->getId())->title();

        $panel->discoverPages(plugin_path($this->getId(), "src/Filament/$id/Pages"), "PelicanProvisioner\\Filament\\$id\\Pages");
        $panel->discoverResources(plugin_path($this->getId(), "src/Filament/$id/Resources"), "PelicanProvisioner\\Filament\\$id\\Resources");
        $panel->discoverWidgets(plugin_path($this->getId(), "src/Filament/$id/Widgets"), "PelicanProvisioner\\Filament\\$id\\Widgets");
    }

    public function boot(Panel $panel): void {}

    /** @return array<string, mixed> */
    public function getSettingsFormData(): array
    {
        /** @var array<string, mixed> $config */
        $config = is_array(config('pelican-provisioner')) ? config('pelican-provisioner') : [];
        $sanitizer = new SettingsSanitizer();
        $settings = $sanitizer->fromConfig($config);

        if (!$settings->createUsable) {
            Notification::make()
                ->title(trans('pelican-provisioner::strings.notifications.settings_invalid_stored'))
                ->danger()
                ->persistent()
                ->send();
        }

        return [
            'database_limit' => $sanitizer->natural($config['database_limit'] ?? null) ?? 0,
            'allocation_limit' => $sanitizer->natural($config['allocation_limit'] ?? null) ?? 0,
            'backup_limit' => $sanitizer->natural($config['backup_limit'] ?? null) ?? 0,
            'subdomain_limit' => $sanitizer->natural($config['subdomain_limit'] ?? null) ?? 0,
            'can_users_update_servers' => $settings->usersCanUpdate,
            'can_users_delete_servers' => $settings->usersCanDelete,
            'deployment_tags' => $sanitizer->displayList($config['deployment_tags'] ?? ''),
            'deployment_ports' => $sanitizer->displayList($config['deployment_ports'] ?? ''),
            'allow_all_eggs' => $settings->allowAllEggs,
            'allowed_eggs' => $settings->allowedEggIds,
            'default_cpu' => $settings->defaultQuota?->cpu,
            'default_memory' => $settings->defaultQuota?->memory,
            'default_disk' => $settings->defaultQuota?->disk,
            'default_server_limit' => $settings->defaultQuota?->serverLimit,
        ];
    }

    /** @return array<int, Section> */
    public function getSettingsForm(): array
    {
        return [
            Section::make(trans('pelican-provisioner::strings.settings.limits'))
                ->description(trans('pelican-provisioner::strings.settings.limits_help'))
                ->columns(fn (): int => $this->hasSubdomainsPlugin() ? 4 : 3)
                ->schema([
                    $this->limitField('database_limit', 'settings.database_limit'),
                    $this->limitField('allocation_limit', 'settings.allocation_limit'),
                    $this->limitField('backup_limit', 'settings.backup_limit'),
                    $this->limitField('subdomain_limit', 'settings.subdomain_limit')
                        ->visible(fn (): bool => $this->hasSubdomainsPlugin()),
                ]),
            Section::make(trans('pelican-provisioner::strings.settings.default_quota'))
                ->description(trans('pelican-provisioner::strings.settings.default_quota_help'))
                ->columns(4)
                ->schema([
                    $this->defaultQuotaField('default_cpu', 'cpu', '%'),
                    $this->defaultQuotaField('default_memory', 'memory', config('panel.use_binary_prefix') ? 'MiB' : 'MB'),
                    $this->defaultQuotaField('default_disk', 'disk', config('panel.use_binary_prefix') ? 'MiB' : 'MB'),
                    $this->defaultQuotaField('default_server_limit', 'server_limit'),
                ]),
            Section::make(trans('pelican-provisioner::strings.settings.permissions'))
                ->columns()
                ->schema([
                    Toggle::make('can_users_update_servers')
                        ->label(trans('pelican-provisioner::strings.settings.can_update'))
                        ->helperText(trans('pelican-provisioner::strings.settings.can_update_help'))
                        ->inline(false),
                    Toggle::make('can_users_delete_servers')
                        ->label(trans('pelican-provisioner::strings.settings.can_delete'))
                        ->helperText(trans('pelican-provisioner::strings.settings.can_delete_help'))
                        ->inline(false),
                ]),
            Section::make(trans('pelican-provisioner::strings.settings.deployment'))
                ->columns()
                ->schema([
                    TagsInput::make('deployment_tags')
                        ->label(trans('pelican-provisioner::strings.settings.tags'))
                        ->helperText(trans('pelican-provisioner::strings.settings.tags_help'))
                        ->splitKeys(['Tab', ','])
                        ->columnSpanFull(),
                    TagsInput::make('deployment_ports')
                        ->label(trans('pelican-provisioner::strings.settings.ports'))
                        ->placeholder('25565-25575')
                        ->helperText(trans('pelican-provisioner::strings.settings.ports_help'))
                        ->splitKeys(['Tab', ','])
                        ->columnSpanFull(),
                    Toggle::make('allow_all_eggs')
                        ->label(trans('pelican-provisioner::strings.settings.allow_all_eggs'))
                        ->helperText(trans('pelican-provisioner::strings.settings.allow_all_eggs_help'))
                        ->inline(false)
                        ->columnSpanFull(),
                    Select::make('allowed_eggs')
                        ->label(trans('pelican-provisioner::strings.settings.eggs'))
                        ->helperText(trans('pelican-provisioner::strings.settings.eggs_help'))
                        ->options(fn (): array => Egg::query()->orderBy('name')->pluck('name', 'id')->all())
                        ->searchable()
                        ->multiple()
                        ->preload(false)
                        ->columnSpanFull(),
                ]),
        ];
    }

    /** @param array<mixed, mixed> $data */
    public function saveSettings(array $data): void
    {
        /** @var array<string, mixed> $stored */
        $stored = is_array(config('pelican-provisioner')) ? config('pelican-provisioner') : [];

        try {
            $environment = (new SettingsSanitizer())->toEnvironment($data, $this->hasSubdomainsPlugin(), $stored);
            $this->writeToEnvironment($environment);
        } catch (InvalidSettingsException) {
            Notification::make()
                ->title(trans('pelican-provisioner::strings.notifications.settings_invalid'))
                ->body(trans('pelican-provisioner::strings.notifications.settings_not_written'))
                ->danger()
                ->persistent()
                ->send();

            return;
        } catch (Throwable $exception) {
            report($exception);

            Notification::make()
                ->title(trans('pelican-provisioner::strings.notifications.settings_invalid'))
                ->body(trans('pelican-provisioner::strings.notifications.settings_write_failed'))
                ->danger()
                ->persistent()
                ->send();

            return;
        }

        Notification::make()
            ->title(trans('pelican-provisioner::strings.notifications.settings_saved'))
            ->success()
            ->send();
    }

    private function defaultQuotaField(string $name, string $label, ?string $suffix = null): TextInput
    {
        $field = TextInput::make($name)
            ->label(trans('pelican-provisioner::strings.'.$label))
            ->helperText(trans($name === 'default_server_limit'
                ? 'pelican-provisioner::strings.hint_server_limit'
                : 'pelican-provisioner::strings.hint_unlimited'))
            ->rules([
                fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get, $name): void {
                    $values = [
                        'default_cpu' => $get('default_cpu'),
                        'default_memory' => $get('default_memory'),
                        'default_disk' => $get('default_disk'),
                        'default_server_limit' => $get('default_server_limit'),
                    ];
                    $values[$name] = $value;

                    try {
                        DefaultQuota::parse($values);
                    } catch (InvalidResourceAmountException) {
                        $fail(trans('pelican-provisioner::strings.settings.default_quota_incomplete'));
                    }
                },
            ]);

        if ($suffix !== null) {
            $field->suffix($suffix);
        }

        return $field;
    }

    private function limitField(string $name, string $label): TextInput
    {
        return TextInput::make($name)
            ->label(trans('pelican-provisioner::strings.'.$label))
            ->helperText(trans('pelican-provisioner::strings.hint_feature_limit'))
            ->required()
            ->integer()
            ->minValue(0)
            ->maxValue(4_294_967_295);
    }

    private function hasSubdomainsPlugin(): bool
    {
        $subdomains = PluginModel::query()->find('subdomains', ['id', 'status']);

        return $subdomains instanceof PluginModel && $subdomains->status === PluginStatus::Enabled;
    }
}
