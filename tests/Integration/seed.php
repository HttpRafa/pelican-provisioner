<?php

declare(strict_types=1);

require __DIR__.'/bootstrap.php';

use App\Models\Allocation;
use App\Models\Egg;
use App\Models\EggVariable;
use App\Models\Node;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use PelicanProvisioner\Models\ResourceQuota;
use Ramsey\Uuid\Uuid;
use Spatie\Permission\Models\Permission;

foreach (['admin' => 1, 'alice' => 0, 'bob' => 0, 'qadmin' => 0] as $username => $isAdmin) {
    Artisan::call('p:user:make', [
        '--email' => $username.'@integration.test',
        '--username' => $username,
        '--password' => Str::random(32),
        '--admin' => $isAdmin,
    ]);
}

/** @param list<array{0: string, 1: string, 2: bool, 3: bool, 4: list<string>}> $variables */
function seed_egg(string $name, array $variables): void
{
    $egg = Egg::query()->forceCreate([
        'uuid' => Uuid::uuid4()->toString(),
        'author' => 'integration@integration.test',
        'name' => $name,
        'description' => 'About '.$name,
        'features' => [],
        'docker_images' => ['Alpine' => 'ghcr.io/parkervcp/yolks:alpine'],
        'file_denylist' => [],
        'startup_commands' => ['Default' => './run.sh'],
        'config_stop' => 'stop',
        'config_startup' => '{"done":"ready"}',
        'config_logs' => '{}',
        'config_files' => '{}',
        'script_install' => "#!/bin/ash\necho ok\n",
        'script_entry' => 'ash',
        'script_container' => 'alpine:3',
        'tags' => [],
    ]);

    foreach ($variables as $sort => [$env, $default, $viewable, $editable, $rules]) {
        EggVariable::query()->forceCreate([
            'egg_id' => $egg->id,
            'sort' => $sort,
            'name' => $env,
            'description' => 'About '.$env,
            'env_variable' => $env,
            'default_value' => $default,
            'user_viewable' => $viewable,
            'user_editable' => $editable,
            'rules' => $rules,
        ]);
    }
}

seed_egg('Audit Allowed', [
    ['SERVER_JAR', 'server.jar', true, true, ['required', 'string', 'max:32']],
    ['BUILD_NUM', '1', true, true, ['required', 'integer', 'min:1']],
    ['LOCKED_PATH', '/defaults', true, false, ['required', 'string']],
    ['HIDDEN_TOKEN', 'hidden-default-secret', false, false, ['required', 'string']],
]);
seed_egg('Audit Optional', [
    ['OPTIONAL_FLAG', '', true, true, ['nullable', 'string', 'max:20']],
]);
seed_egg('Audit Big Number', [
    ['STEAM_ID', '1', true, true, ['required', 'numeric']],
]);
seed_egg('Audit Forbidden', [
    ['LICENSE_KEY', 'CORP-LICENSE-1234', true, false, ['required', 'string']],
]);

function seed_node(string $name, bool $public, array $tags, int $capacity, int $firstPort): void
{
    $node = Node::query()->create([
        'name' => $name,
        'description' => 'Integration',
        'scheme' => 'http',
        'fqdn' => 'panel',
        'public' => $public,
        'behind_proxy' => false,
        'maintenance_mode' => false,
        'memory' => $capacity,
        'memory_overallocate' => 0,
        'disk' => $capacity * 5,
        'disk_overallocate' => 0,
        'cpu' => 400,
        'cpu_overallocate' => 0,
        'upload_size' => 256,
        'daemon_listen' => 8080,
        'daemon_connect' => 8080,
        'daemon_sftp' => 2022,
        'daemon_sftp_alias' => null,
        'daemon_base' => '/var/lib/pelican/volumes',
        'tags' => $tags,
    ]);

    for ($port = $firstPort; $port < $firstPort + 12; $port++) {
        Allocation::query()->create(['node_id' => $node->id, 'ip' => '10.10.10.10', 'port' => $port]);
    }
}

// Only the first node may ever be chosen: the others are private or lack the tag, and they are larger so that a wrong choice would not fail on its own.
seed_node('Public Tagged', true, ['user_creatable_servers'], 4096, 25565);
seed_node('Private Tagged', false, ['user_creatable_servers'], 900000, 26565);
seed_node('Public Untagged', true, ['other'], 900000, 27565);

file_put_contents('/tmp/wings-token-id', (string) Node::query()->where('name', 'Public Tagged')->value('daemon_token_id'));

$alice = User::query()->where('username', 'alice')->firstOrFail();
ResourceQuota::query()->create(['user_id' => $alice->id, 'cpu' => 200, 'memory' => 2048, 'disk' => 10240, 'server_limit' => 3]);

$viewer = Role::query()->create(['name' => 'QuotaViewer', 'guard_name' => 'web']);
$viewer->givePermissionTo(Permission::findOrCreate('viewList provisionerQuota', 'web'));
User::query()->where('username', 'qadmin')->firstOrFail()->syncRoles([$viewer]);

echo "Seeded.\n";
