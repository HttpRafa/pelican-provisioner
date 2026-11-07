<?php

declare(strict_types=1);

namespace PelicanProvisioner\Services;

use App\Enums\PluginStatus;
use App\Exceptions\Service\Deployment\NoViableAllocationException;
use App\Facades\Activity;
use App\Models\Allocation;
use App\Models\Egg;
use App\Models\EggVariable;
use App\Models\Node;
use App\Models\Plugin;
use App\Models\Server;
use App\Models\User;
use App\Repositories\Daemon\DaemonServerRepository;
use App\Services\Deployment\AllocationSelectionService;
use App\Services\Deployment\FindViableNodesService;
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
use PelicanProvisioner\Domain\AccessMode;
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
use PelicanProvisioner\Domain\QuotaAllowlist;
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
 * The egg allowlist and the quota are checked here, not only in the form. One lock per user stops two requests from both passing the quota. After create, the quota is read again and the new server is deleted if it no longer fits. The allocation is chosen here because the panel's deployment path creates a server without one when no ports are set. Subdomain support is read from the panel's plugin list, because this panel version has no plugins table.
 */
final class ServerProvisioner
{
    private const int CREATE_ATTEMPTS = 10;

    private const int CHANGE_ATTEMPTS = 30;

    private const int DECAY_SECONDS = 600;

    private ?bool $subdomainColumn = null;

    public function __construct(
        private readonly ServerCreationService $creation,
        private readonly ServerDeletionService $deletion,
        private readonly VariableValidatorService $validator,
        private readonly FindViableNodesService $nodes,
        private readonly AllocationSelectionService $allocations,
        private readonly UsageQuery $usage,
        private readonly UserLock $lock,
        private readonly SettingsRepository $settings,
        private readonly QuotaPolicy $policy,
        private readonly EnvironmentBuilder $environment,
        private readonly EggAllowlist $eggs,
        private readonly QuotaAllowlist $allowlist,
    ) {}

    /** @param mixed $variables Repeater rows from the create form. */
    public function create(mixed $name, mixed $eggId, mixed $cpu, mixed $memory, mixed $disk, mixed $variables, mixed $nodeId = null): Server
    {
        $actor = $this->actor();

        try {
            $this->hitRateLimit($actor, 'create', self::CREATE_ATTEMPTS);

            return $this->lock->run((int) $actor->id, fn (): Server => $this->createWhileLocked(
                $actor,
                $name,
                $eggId,
                $cpu,
                $memory,
                $disk,
                $variables,
                $nodeId,
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
        $this->assertOwner($actor, $server, 'resize');

        $settings = $this->settings->current();

        if (!$settings->usersCanUpdate) {
            $this->deny($actor, $server, 'resize', 'disabled');
        }

        try {
            $this->hitRateLimit($actor, 'change', self::CHANGE_ATTEMPTS);

            $before = $this->lock->run((int) $actor->id, fn (): ResourceAmounts => DB::transaction(function () use ($server, $cpu, $memory, $disk): ResourceAmounts {
                /** @var Server|null $locked */
                $locked = Server::query()->whereKey($server->id)->lockForUpdate()->first();

                if (!$locked instanceof Server) {
                    abort(404);
                }

                $actor = $this->actor();
                $this->assertOwner($actor, $locked, 'resize');

                if ($locked->isInConflictState()) {
                    $this->deny($actor, $locked, 'resize', 'conflict_state');
                }

                $quota = $this->quotaForUpdate($actor, $locked);
                $requested = $this->amounts($cpu, $memory, $disk);
                $current = new ResourceAmounts((int) $locked->cpu, (int) $locked->memory, (int) $locked->disk);

                $this->policy->assertCanUpdate(
                    $quota->toQuota(),
                    $this->usage->fresh((int) $actor->id),
                    $current,
                    $requested,
                );
                $this->assertNodeHasRoom($locked, $requested->growthFrom($current));

                $locked->forceFill([
                    'cpu' => $requested->cpu,
                    'memory' => $requested->memory,
                    'disk' => $requested->disk,
                ])->save();

                return $current;
            }));
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

        $this->usage->forget((int) $actor->id);
        $server->refresh();
        $this->audit($server, 'server:provisioner.resources', [
            'old_cpu' => $before->cpu,
            'old_memory' => $before->memory,
            'old_disk' => $before->disk,
            'new_cpu' => (int) $server->cpu,
            'new_memory' => (int) $server->memory,
            'new_disk' => (int) $server->disk,
        ]);

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
        $this->assertOwner($actor, $server, 'delete');

        if (!$this->settings->current()->usersCanDelete) {
            $this->deny($actor, $server, 'delete', 'disabled');
        }

        $properties = [
            'name' => (string) $server->name,
            'uuid' => (string) $server->uuid,
            'cpu' => (int) $server->cpu,
            'memory' => (int) $server->memory,
            'disk' => (int) $server->disk,
        ];

        try {
            $this->hitRateLimit($actor, 'change', self::CHANGE_ATTEMPTS);

            $this->lock->run((int) $actor->id, function () use ($server, $actor): void {
                $locked = Server::query()->whereKey($server->id)->first();

                if (!$locked instanceof Server) {
                    abort(404);
                }

                $this->assertOwner($actor, $locked, 'delete');

                if ($locked->isInConflictState()) {
                    $this->deny($actor, $locked, 'delete', 'conflict_state');
                }

                if ($this->readQuota((int) $actor->id) === null) {
                    $this->deny($actor, $locked, 'delete', 'no_quota');
                }

                $this->deletion->handle($locked);
                $this->usage->forget((int) $actor->id);
            });
        } catch (ProvisioningException|HttpException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            throw new ProvisioningException(ProvisioningFailure::Unknown);
        }

        $this->audit($server, 'server:provisioner.delete', $properties);
    }

    private function createWhileLocked(User $actor, mixed $name, mixed $eggId, mixed $cpu, mixed $memory, mixed $disk, mixed $variables, mixed $nodeId): Server
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
        $quota = $this->readQuota((int) $actor->id);

        if (!$quota instanceof ResourceQuota) {
            $this->logDenied($actor, null, 'create', 'no_quota');

            throw new ProvisioningException(ProvisioningFailure::Forbidden);
        }

        $eggChoice = $this->allowlist->eggs(
            $settings->allowAllEggs,
            $settings->allowedEggIds,
            $quota->egg_mode,
            $quota->allowed_egg_ids,
            (bool) $quota->allow_all_eggs,
        );

        if ($resolvedEggId === null || !$this->eggs->permits($resolvedEggId, $eggChoice->ids, $eggChoice->allowAll, $eggChoice->excludedIds)) {
            $this->logDenied($actor, null, 'create', 'egg_not_allowed');

            throw new ProvisioningException(ProvisioningFailure::EggNotAllowed);
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

        $allocation = $this->chooseAllocation($actor, $requested, $settings, $quota, $nodeId);

        try {
            $server = $this->creation->handle([
                'name' => $serverName,
                'owner_id' => $actor->id,
                'egg_id' => $egg->id,
                'node_id' => $allocation->node_id,
                'allocation_id' => $allocation->id,
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
            ]);
        } catch (ValidationException $exception) {
            if (array_key_exists('allocation_id', $exception->errors())) {
                throw new ProvisioningException(ProvisioningFailure::NoAllocation);
            }

            throw new ProvisioningException(ProvisioningFailure::Variables, $this->validationDetail($exception));
        } catch (ModelNotFoundException) {
            throw new ProvisioningException(ProvisioningFailure::EggNotAllowed);
        } catch (RequestException $exception) {
            $this->removeUnfinishedServer((int) $actor->id, (int) $allocation->id);

            report($exception);

            throw new ProvisioningException(ProvisioningFailure::Unknown);
        }

        $latest = $this->readQuota((int) $actor->id);

        if (!$latest instanceof ResourceQuota) {
            $this->deletion->withForce()->handle($server);

            throw new ProvisioningException(ProvisioningFailure::Forbidden);
        }

        try {
            $this->policy->assertWithin($latest->toQuota(), $this->usage->fresh((int) $actor->id));
        } catch (QuotaViolationException) {
            $this->deletion->withForce()->handle($server);

            throw new ProvisioningException(ProvisioningFailure::Quota);
        }

        $this->applySubdomainLimit($server, $settings);
        $this->audit($server, 'server:provisioner.create', [
            'egg_id' => (int) $egg->id,
            'cpu' => $requested->cpu,
            'memory' => $requested->memory,
            'disk' => $requested->disk,
        ]);

        Log::info('pelican-provisioner.created', [
            'server_id' => $server->id,
            'user_id' => $actor->id,
            'egg_id' => $egg->id,
        ]);

        return $server;
    }

    private function chooseAllocation(User $actor, ResourceAmounts $requested, DeploymentSettings $settings, ResourceQuota $quota, mixed $submittedNode): Allocation
    {
        $tagged = $this->nodes
            ->handle($requested->memory, $requested->disk, $requested->cpu, $settings->tags)
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
        $selected = QuotaAllowlist::ids($quota->allowed_node_ids);
        $mode = AccessMode::parse($quota->node_mode);

        if ($mode === AccessMode::Additive || $mode === AccessMode::Overwrite) {
            $selected = $this->viableNodeIds($selected, $requested);
        }

        $resolved = $this->allowlist->nodes($tagged, $quota->node_mode, $selected);
        $placement = $this->allowlist->placement($submittedNode, $resolved, $quota->node_mode);

        if ($placement->rejected) {
            $this->logDenied($actor, null, 'create', 'node_not_allowed');

            throw new ProvisioningException(ProvisioningFailure::NodeNotAllowed);
        }

        if ($placement->nodeIds === []) {
            throw new ProvisioningException(ProvisioningFailure::NoNode);
        }

        try {
            return $this->allocations
                ->setDedicated(false)
                ->setNodes($placement->nodeIds)
                ->setPorts($settings->ports)
                ->handle();
        } catch (NoViableAllocationException) {
            throw new ProvisioningException(ProvisioningFailure::NoAllocation);
        }
    }

    /**
     * The panel only cleans up after a ConnectionException. An HTTP error from the node would leave a row that stays "installing", counts against the quota, and cannot be opened by its owner.
     */
    private function removeUnfinishedServer(int $ownerId, int $allocationId): void
    {
        $server = Server::query()
            ->where('owner_id', $ownerId)
            ->where('allocation_id', $allocationId)
            ->first();

        if ($server instanceof Server) {
            $this->deletion->withForce()->handle($server);
        }
    }

    /** @param list<int> $ids */
    private function viableNodeIds(array $ids, ResourceAmounts $requested): array
    {
        if ($ids === []) {
            return [];
        }

        $kept = [];

        foreach (Node::query()->whereIn('id', $ids)->get() as $node) {
            if ($node instanceof Node && $node->isViable($requested->memory, $requested->disk, $requested->cpu)) {
                $kept[] = (int) $node->id;
            }
        }

        return $kept;
    }

    private function assertNodeHasRoom(Server $server, ResourceAmounts $growth): void
    {
        if ($growth->isZero()) {
            return;
        }

        /** @var Node|null $node */
        $node = Node::query()->whereKey($server->node_id)->lockForUpdate()->first();

        if (!$node instanceof Node || !$node->isViable($growth->memory, $growth->disk, $growth->cpu)) {
            throw new ProvisioningException(ProvisioningFailure::NodeFull);
        }
    }

    /**
     * The server already exists and the column default of 0 is the safe value, so a failure here must not report the create as failed.
     */
    private function applySubdomainLimit(Server $server, DeploymentSettings $settings): void
    {
        try {
            if (!$this->subdomainsEnabled() || !$this->hasSubdomainColumn()) {
                return;
            }

            $server->forceFill([
                'subdomain_limit' => $settings->subdomainLimit,
            ])->save();
        } catch (Throwable $exception) {
            report($exception);
        }
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

    private function readQuota(int $userId): ?ResourceQuota
    {
        return ResourceQuota::query()
            ->where('user_id', $userId)
            ->useWritePdo()
            ->first(['id', 'user_id', 'cpu', 'memory', 'disk', 'server_limit', 'egg_mode', 'node_mode', 'allow_all_eggs', 'allowed_egg_ids', 'allowed_node_ids']);
    }

    private function quotaForUpdate(User $actor, Server $server): ResourceQuota
    {
        /** @var ResourceQuota|null $quota */
        $quota = ResourceQuota::query()
            ->where('user_id', $actor->id)
            ->lockForUpdate()
            ->first(['id', 'user_id', 'cpu', 'memory', 'disk', 'server_limit']);

        if (!$quota instanceof ResourceQuota) {
            $this->deny($actor, $server, 'resize', 'no_quota');
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

    private function assertOwner(User $actor, Server $server, string $action): void
    {
        if ((int) $actor->id !== (int) $server->owner_id) {
            $this->deny($actor, $server, $action, 'not_owner');
        }
    }

    private function deny(User $actor, Server $server, string $action, string $reason): never
    {
        $this->logDenied($actor, $server, $action, $reason);

        abort(403);
    }

    private function logDenied(User $actor, ?Server $server, string $action, string $reason): void
    {
        Log::warning('pelican-provisioner.denied', [
            'user_id' => $actor->id,
            'server_id' => $server?->id,
            'action' => $action,
            'reason' => $reason,
        ]);
    }

    private function hitRateLimit(User $actor, string $action, int $attempts): void
    {
        $key = 'pelican-provisioner:'.$action.':'.$actor->id;

        if (RateLimiter::tooManyAttempts($key, $attempts)) {
            $this->logDenied($actor, null, $action, 'rate_limited');

            throw new ProvisioningException(ProvisioningFailure::RateLimited);
        }

        RateLimiter::hit($key, self::DECAY_SECONDS);
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

    /** @param array<string, int|string> $properties */
    private function audit(Server $server, string $event, array $properties): void
    {
        if (!class_exists(Activity::class)) {
            return;
        }

        try {
            Activity::event($event)->subject($server)->property($properties)->log();
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
