<?php

declare(strict_types=1);

namespace PelicanProvisioner\Services;

use App\Enums\PluginStatus;
use App\Exceptions\Service\Deployment\NoViableAllocationException;
use App\Exceptions\Service\Deployment\NoViableNodeException;
use App\Facades\Activity;
use App\Models\Egg;
use App\Models\EggVariable;
use App\Models\Objects\DeploymentObject;
use App\Models\Plugin;
use App\Models\Server;
use App\Models\User;
use App\Repositories\Daemon\DaemonServerRepository;
use App\Services\Servers\ServerCreationService;
use App\Services\Servers\ServerDeletionService;
use App\Services\Servers\VariableValidatorService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use PelicanProvisioner\Domain\DeploymentSettings;
use PelicanProvisioner\Domain\EggAllowlist;
use PelicanProvisioner\Domain\EggVariableDefinition;
use PelicanProvisioner\Domain\EnvironmentBuilder;
use PelicanProvisioner\Domain\InvalidEnvironmentException;
use PelicanProvisioner\Domain\InvalidResourceAmountException;
use PelicanProvisioner\Domain\InvalidServerNameException;
use PelicanProvisioner\Domain\PositiveId;
use PelicanProvisioner\Domain\ProvisioningException;
use PelicanProvisioner\Domain\ProvisioningFailure;
use PelicanProvisioner\Domain\QuotaPolicy;
use PelicanProvisioner\Domain\QuotaViolationException;
use PelicanProvisioner\Domain\ResourceAmountParser;
use PelicanProvisioner\Domain\ResourceAmounts;
use PelicanProvisioner\Domain\ServerName;
use PelicanProvisioner\Domain\SubmittedVariables;
use PelicanProvisioner\Models\ResourceQuota;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * Creates, resizes, and deletes a server for the signed-in user.
 *
 * The egg allowlist and the quota are checked here, not only in the form. One lock per user stops two requests from both passing the quota. After create, the quota is read again and the new server is deleted if it no longer fits. Subdomain support is read from the panel's plugin list, because this panel version has no plugins table.
 */
final class ServerProvisioner
{
    private const int CREATE_ATTEMPTS = 10;

    private const int CREATE_DECAY_SECONDS = 600;

    private ?bool $subdomainColumn = null;

    public function __construct(
        private readonly ServerCreationService $creation,
        private readonly ServerDeletionService $deletion,
        private readonly VariableValidatorService $validator,
        private readonly UsageQuery $usage,
        private readonly UserLock $lock,
        private readonly SettingsRepository $settings,
        private readonly QuotaPolicy $policy,
        private readonly EnvironmentBuilder $environment,
        private readonly EggAllowlist $eggs,
    ) {}

    /** @param mixed $variables Repeater rows from the create form. */
    public function create(mixed $name, mixed $eggId, mixed $cpu, mixed $memory, mixed $disk, mixed $variables): Server
    {
        $actor = $this->actor();

        try {
            $this->hitRateLimit((int) $actor->id);

            return $this->lock->run((int) $actor->id, fn (): Server => $this->createWhileLocked(
                $actor,
                $name,
                $eggId,
                $cpu,
                $memory,
                $disk,
                $variables,
            ));
        } catch (ProvisioningException|HttpException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            throw new ProvisioningException(ProvisioningFailure::Unknown);
        }
    }

    public function updateResources(Server $server, mixed $cpu, mixed $memory, mixed $disk): void
    {
        $actor = $this->actor();
        $this->assertOwner($actor, $server);

        $settings = $this->settings->current();

        if (!$settings->usersCanUpdate) {
            abort(403);
        }

        try {
            $this->lock->run((int) $actor->id, function () use ($server, $cpu, $memory, $disk): void {
                DB::transaction(function () use ($server, $cpu, $memory, $disk): void {
                    $locked = Server::query()->whereKey($server->id)->lockForUpdate()->first();

                    if (!$locked instanceof Server) {
                        abort(404);
                    }

                    $actor = $this->actor();
                    $this->assertOwner($actor, $locked);

                    if ($locked->isInConflictState()) {
                        abort(403);
                    }

                    $quota = $this->quotaForUpdate((int) $actor->id);
                    $requested = $this->amounts($cpu, $memory, $disk);
                    $current = new ResourceAmounts((int) $locked->cpu, (int) $locked->memory, (int) $locked->disk);

                    $this->policy->assertCanUpdate(
                        $quota->toQuota(),
                        $this->usage->fresh((int) $actor->id),
                        $current,
                        $requested,
                    );

                    $locked->forceFill([
                        'cpu' => $requested->cpu,
                        'memory' => $requested->memory,
                        'disk' => $requested->disk,
                    ])->save();
                });
            });
        } catch (QuotaViolationException) {
            throw new ProvisioningException(ProvisioningFailure::Quota);
        } catch (InvalidResourceAmountException) {
            throw new ProvisioningException(ProvisioningFailure::Quota);
        } catch (ProvisioningException|HttpException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            throw new ProvisioningException(ProvisioningFailure::Unknown);
        }

        $server->refresh();
        $this->audit($server, 'server:provisioner.resources');

        try {
            app(DaemonServerRepository::class)->setServer($server)->sync();
        } catch (ConnectionException|RequestException $exception) {
            Log::warning('pelican-provisioner.sync_failed', [
                'server_id' => $server->id,
                'user_id' => $actor->id,
            ]);

            throw $exception;
        }
    }

    public function delete(Server $server): void
    {
        $actor = $this->actor();
        $this->assertOwner($actor, $server);

        if (!$this->settings->current()->usersCanDelete) {
            abort(403);
        }

        try {
            $this->lock->run((int) $actor->id, function () use ($server, $actor): void {
                $locked = Server::query()->whereKey($server->id)->first();

                if (!$locked instanceof Server) {
                    abort(404);
                }

                $this->assertOwner($actor, $locked);
                $this->deletion->handle($locked);
                $this->usage->forget((int) $actor->id);
            });
        } catch (ProvisioningException|HttpException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            throw new ProvisioningException(ProvisioningFailure::Unknown);
        }

        $this->audit($server, 'server:provisioner.delete');
    }

    private function createWhileLocked(User $actor, mixed $name, mixed $eggId, mixed $cpu, mixed $memory, mixed $disk, mixed $variables): Server
    {
        $settings = $this->settings->current();

        if (!$settings->createUsable) {
            throw new ProvisioningException(ProvisioningFailure::InvalidSettings);
        }

        try {
            $serverName = ServerName::parse($name);
            $requested = $this->amounts($cpu, $memory, $disk);
        } catch (InvalidServerNameException) {
            throw new ProvisioningException(ProvisioningFailure::InvalidName);
        } catch (InvalidResourceAmountException) {
            throw new ProvisioningException(ProvisioningFailure::Quota);
        }

        $resolvedEggId = PositiveId::parse($eggId);

        if ($resolvedEggId === null || !$this->eggs->permits($resolvedEggId, $settings->allowedEggIds)) {
            throw new ProvisioningException(ProvisioningFailure::EggNotAllowed);
        }

        $quota = ResourceQuota::query()
            ->where('user_id', $actor->id)
            ->useWritePdo()
            ->first(['id', 'user_id', 'cpu', 'memory', 'disk', 'server_limit']);

        if (!$quota instanceof ResourceQuota) {
            throw new ProvisioningException(ProvisioningFailure::Forbidden);
        }

        try {
            $this->policy->assertCanCreate($quota->toQuota(), $this->usage->fresh((int) $actor->id), $requested);
        } catch (QuotaViolationException) {
            throw new ProvisioningException(ProvisioningFailure::Quota);
        }

        $egg = Egg::query()->whereKey($resolvedEggId)->first(['id']);

        if (!$egg instanceof Egg) {
            throw new ProvisioningException(ProvisioningFailure::EggNotAllowed);
        }

        $egg->load(['variables' => fn ($query) => $query
            ->select(['id', 'egg_id', 'env_variable', 'default_value', 'user_viewable', 'user_editable', 'rules'])
            ->orderBy('sort'),
        ]);

        try {
            $submitted = SubmittedVariables::fromRepeater($variables);
            $definitions = $egg->variables
                ->map(fn (EggVariable $variable): EggVariableDefinition => new EggVariableDefinition(
                    environmentName: $variable->env_variable,
                    defaultValue: (string) $variable->default_value,
                    userViewable: (bool) $variable->user_viewable,
                    userEditable: (bool) $variable->user_editable,
                ))
                ->all();
            $environment = $this->environment->build($definitions, $submitted);
            $this->validator->setUserLevel(User::USER_LEVEL_USER)->handle($egg->id, $environment);
        } catch (InvalidEnvironmentException|ValidationException $exception) {
            $detail = $exception instanceof ValidationException
                ? $this->validationDetail($exception)
                : null;

            throw new ProvisioningException(ProvisioningFailure::Variables, $detail);
        }

        $deployment = (new DeploymentObject())
            ->setDedicated(false)
            ->setTags($settings->tags)
            ->setPorts($settings->ports);

        try {
            $server = $this->creation->handle([
                'name' => $serverName,
                'owner_id' => $actor->id,
                'egg_id' => $egg->id,
                'cpu' => $requested->cpu,
                'memory' => $requested->memory,
                'disk' => $requested->disk,
                'swap' => 0,
                'io' => 500,
                'environment' => $environment,
                'skip_scripts' => false,
                'start_on_completion' => true,
                'oom_killer' => false,
                'database_limit' => $settings->databaseLimit,
                'allocation_limit' => $settings->allocationLimit,
                'backup_limit' => $settings->backupLimit,
            ], $deployment);
        } catch (NoViableNodeException) {
            throw new ProvisioningException(ProvisioningFailure::NoNode);
        } catch (NoViableAllocationException) {
            throw new ProvisioningException(ProvisioningFailure::NoAllocation);
        } catch (ValidationException $exception) {
            throw new ProvisioningException(ProvisioningFailure::Variables, $this->validationDetail($exception));
        } catch (ModelNotFoundException) {
            throw new ProvisioningException(ProvisioningFailure::EggNotAllowed);
        }

        try {
            $quota->refresh();
            $this->policy->assertWithin($quota->toQuota(), $this->usage->fresh((int) $actor->id));
        } catch (QuotaViolationException) {
            $this->deletion->withForce()->handle($server);

            throw new ProvisioningException(ProvisioningFailure::Quota);
        }

        $this->applySubdomainLimit($server, $settings);
        $this->audit($server, 'server:provisioner.create');

        Log::info('pelican-provisioner.created', [
            'server_id' => $server->id,
            'user_id' => $actor->id,
            'egg_id' => $egg->id,
        ]);

        return $server;
    }

    private function applySubdomainLimit(Server $server, DeploymentSettings $settings): void
    {
        if (!$this->subdomainsEnabled() || !$this->hasSubdomainColumn()) {
            return;
        }

        $server->forceFill([
            'subdomain_limit' => $settings->subdomainLimit,
        ])->save();
    }

    private function subdomainsEnabled(): bool
    {
        try {
            $plugin = Plugin::query()->find('subdomains', ['id', 'status']);
        } catch (Throwable) {
            return false;
        }

        return $plugin instanceof Plugin && $plugin->status === PluginStatus::Enabled;
    }

    private function hasSubdomainColumn(): bool
    {
        return $this->subdomainColumn ??= Schema::hasColumn('servers', 'subdomain_limit');
    }

    private function quotaForUpdate(int $userId): ResourceQuota
    {
        $quota = ResourceQuota::query()
            ->where('user_id', $userId)
            ->lockForUpdate()
            ->first(['id', 'user_id', 'cpu', 'memory', 'disk', 'server_limit']);

        if (!$quota instanceof ResourceQuota) {
            abort(403);
        }

        return $quota;
    }

    private function amounts(mixed $cpu, mixed $memory, mixed $disk): ResourceAmounts
    {
        return new ResourceAmounts(
            ResourceAmountParser::parse($cpu),
            ResourceAmountParser::parse($memory),
            ResourceAmountParser::parse($disk),
        );
    }

    private function actor(): User
    {
        $actor = user();

        if (!$actor instanceof User) {
            abort(403);
        }

        return $actor;
    }

    private function assertOwner(User $actor, Server $server): void
    {
        if ((int) $actor->id !== (int) $server->owner_id) {
            abort(403);
        }
    }

    private function hitRateLimit(int $userId): void
    {
        $key = 'pelican-provisioner:create:'.$userId;

        if (RateLimiter::tooManyAttempts($key, self::CREATE_ATTEMPTS)) {
            throw new ProvisioningException(ProvisioningFailure::Busy);
        }

        RateLimiter::hit($key, self::CREATE_DECAY_SECONDS);
    }

    private function validationDetail(ValidationException $exception): string
    {
        $messages = [];

        foreach ($exception->errors() as $error) {
            foreach ($error as $message) {
                if (is_string($message)) {
                    $messages[] = $message;
                }
            }
        }

        $text = trim((string) preg_replace('/\s+/', ' ', implode(' ', $messages)));
        $text = (string) preg_replace('/[\x00-\x1F\x7F]/', '', $text);

        if (mb_strlen($text) > 300) {
            $text = mb_substr($text, 0, 300);
        }

        return $text;
    }

    private function audit(Server $server, string $event): void
    {
        if (!class_exists(Activity::class)) {
            return;
        }

        try {
            Activity::event($event)->subject($server)->log();
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
