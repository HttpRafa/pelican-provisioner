<?php

declare(strict_types=1);

use App\Models\Server;
use App\Models\User;
use App\Services\Servers\ServerDeletionService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\RateLimiter;
use PelicanProvisioner\Domain\ProvisioningException;
use PelicanProvisioner\Models\ResourceQuota;
use PelicanProvisioner\Services\ServerProvisioner;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

require '/var/www/html/vendor/autoload.php';

$app = require '/var/www/html/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$GLOBALS['failed'] = 0;
$GLOBALS['passed'] = 0;
$GLOBALS['defaultSettings'] = config('pelican-provisioner');

function section(string $title): void
{
    echo "\n$title\n";
}

function check(string $label, mixed $actual, mixed $expected): void
{
    if ($actual === $expected) {
        $GLOBALS['passed']++;
        echo "  ok    $label\n";

        return;
    }

    $GLOBALS['failed']++;
    echo "  FAIL  $label\n        expected ".json_encode($expected)."\n        got      ".json_encode($actual)."\n";
}

function finish(): never
{
    echo "\n{$GLOBALS['passed']} passed, {$GLOBALS['failed']} failed\n";

    exit($GLOBALS['failed'] > 0 ? 1 : 0);
}

function as_user(string $username): User
{
    $user = User::query()->where('username', $username)->firstOrFail();
    auth()->setUser($user);
    app()->forgetScopedInstances();

    return $user;
}

function provisioner(): ServerProvisioner
{
    app()->forgetScopedInstances();

    return app(ServerProvisioner::class);
}

function outcome(callable $action): string
{
    try {
        $action();

        return 'allowed';
    } catch (ProvisioningException $exception) {
        return $exception->failure->value;
    } catch (HttpExceptionInterface $exception) {
        return 'http '.$exception->getStatusCode();
    } catch (Throwable $exception) {
        return 'error '.class_basename($exception).': '.$exception->getMessage();
    }
}

/** @param list<array<string, mixed>> $rows */
function create_server(string $name, int $eggId, int $cpu = 10, int $memory = 64, int $disk = 64, array $rows = []): Server
{
    return provisioner()->create($name, $eggId, $cpu, $memory, $disk, $rows);
}

function resize(Server $server, int $cpu, int $memory, int $disk): void
{
    provisioner()->updateResources(Server::query()->findOrFail($server->id), $cpu, $memory, $disk);
}

function mark_installed(Server $server): Server
{
    $server->forceFill(['status' => null])->save();

    return $server->refresh();
}

/** @return array<string, string> */
function stored_environment(Server $server): array
{
    $environment = $server->serverVariables()->with('variable')->get()
        ->mapWithKeys(fn ($row): array => [$row->variable->env_variable => $row->variable_value])
        ->all();
    ksort($environment);

    return $environment;
}

function node_answers(int $status): void
{
    file_put_contents('/tmp/wings-status', (string) $status);
}

function clear_rate_limits(): void
{
    foreach (User::query()->pluck('id') as $id) {
        RateLimiter::clear('pelican-provisioner:create:'.$id);
        RateLimiter::clear('pelican-provisioner:change:'.$id);
    }
}

function remove_all_servers(): void
{
    foreach (Server::query()->get() as $server) {
        app(ServerDeletionService::class)->withForce()->handle($server);
    }
}

/** Every section starts from the same state: no servers, the real default settings plus $settings, and alice acting with her usual quota. */
function reset_state(array $settings = []): void
{
    node_answers(204);
    remove_all_servers();
    clear_rate_limits();
    config()->set('pelican-provisioner', [...$GLOBALS['defaultSettings'], ...$settings]);

    $alice = User::query()->where('username', 'alice')->firstOrFail();
    ResourceQuota::query()->updateOrCreate(['user_id' => $alice->id], [
        'cpu' => 200,
        'memory' => 2048,
        'disk' => 10240,
        'server_limit' => 3,
        'egg_mode' => null,
        'node_mode' => null,
        'allow_all_eggs' => false,
        'allowed_egg_ids' => null,
        'allowed_node_ids' => null,
    ]);

    as_user('alice');
}
