#!/bin/sh
# Seeds a local panel so the plugin can be clicked through. It is not part of a panel install.
# Run it again at any time: it keeps existing data and prints fresh passwords.
set -eu

cd "$(dirname "$0")"

# compose.yml mounts Wings' folders at their host path, so it needs this directory's absolute path.
PWD="$(pwd -P)"
export PWD
export PANEL_PORT="${PANEL_PORT:-8088}"
export WINGS_PORT="${WINGS_PORT:-18080}"
export WINGS_SFTP_PORT="${WINGS_SFTP_PORT:-2022}"
# Redis runs on its defaults and stays up, so it shows quickly whether servers install and start.
egg_url="${EGG_URL:-https://raw.githubusercontent.com/pelican-eggs/database/refs/heads/main/redis/redis-7/egg-redis-7.json}"
# Server files are written as you, so they can be removed without root.
WINGS_UID="${WINGS_UID:-$(id -u)}"
WINGS_GID="${WINGS_GID:-$(id -g)}"
export WINGS_UID WINGS_GID

panel_url="http://127.0.0.1:$PANEL_PORT"
admin_password="$(head -c 18 /dev/urandom | base64 | tr -dc 'A-Za-z0-9')"
friend_password="$(head -c 18 /dev/urandom | base64 | tr -dc 'A-Za-z0-9')"

mkdir -p wings/etc wings/volumes wings/tmp

echo "Starting the panel..."
docker compose up -d panel

echo "Waiting until the panel answers..."
i=0
until curl -fsS -o /dev/null "$panel_url/installer" || curl -fsS -o /dev/null "$panel_url/admin/login"; do
  i=$((i + 1))
  if [ "$i" -gt 60 ]; then
    echo "The panel did not become ready. Recent logs:"
    docker compose logs --tail=80 panel
    exit 1
  fi
  sleep 5
done

echo "Seeding and creating accounts..."
docker compose exec -T panel php artisan db:seed --force
docker compose exec -T -e ADMIN_PASSWORD="$admin_password" -e FRIEND_PASSWORD="$friend_password" panel php artisan tinker --execute="$(cat <<'PHP'
$accounts = [
    ['admin@example.com', 'admin', getenv('ADMIN_PASSWORD'), true],
    ['friend@example.com', 'friend', getenv('FRIEND_PASSWORD'), false],
];

foreach ($accounts as [$email, $username, $password, $rootAdmin]) {
    $user = App\Models\User::query()->where('email', $email)->first();

    if ($user) {
        $user->forceFill(['password' => Illuminate\Support\Facades\Hash::make($password)])->save();
    } else {
        app(App\Services\Users\UserCreationService::class)->handle([
            'email' => $email,
            'username' => $username,
            'password' => $password,
            'root_admin' => $rootAdmin,
        ]);
    }
}
PHP
)"

echo "Installing the plugin..."
# The panel writes install state into plugin.json. The bind mount is owned by the host user.
chmod a+w ../plugin.json
if docker compose exec -T panel php artisan p:plugin:list | grep -q 'Pelican Provisioner.*enabled'; then
  echo "Already installed."
else
  docker compose exec -T panel php artisan p:plugin:install pelican-provisioner
fi

echo "Creating a quota, a node with ports, and one egg..."
docker compose exec -T -e STACK_DIR="$PWD" -e WINGS_PORT="$WINGS_PORT" -e WINGS_SFTP_PORT="$WINGS_SFTP_PORT" -e EGG_URL="$egg_url" panel php artisan tinker --execute="$(cat <<'PHP'
$friend = App\Models\User::query()->where('email', 'friend@example.com')->first();

if ($friend && ! PelicanProvisioner\Models\ResourceQuota::query()->where('user_id', $friend->id)->exists()) {
    PelicanProvisioner\Models\ResourceQuota::query()->create([
        'user_id' => $friend->id,
        'cpu' => 200,
        'memory' => 2048,
        'disk' => 10240,
        'server_limit' => 2,
    ]);
}

// Server files live in docker/wings/volumes, at the same path on the host and in the Wings container.
$node = App\Models\Node::query()->updateOrCreate(['name' => 'Local'], [
    'description' => 'Wings on this machine',
    'scheme' => 'http',
    'fqdn' => 'wings',
    'public' => true,
    'behind_proxy' => false,
    'maintenance_mode' => false,
    'memory' => 8192,
    'memory_overallocate' => 0,
    'disk' => 51200,
    'disk_overallocate' => 0,
    'cpu' => 400,
    'cpu_overallocate' => 0,
    'upload_size' => 256,
    'daemon_listen' => (int) getenv('WINGS_PORT'),
    'daemon_connect' => (int) getenv('WINGS_PORT'),
    'daemon_sftp' => (int) getenv('WINGS_SFTP_PORT'),
    'daemon_sftp_alias' => null,
    'daemon_base' => getenv('STACK_DIR').'/wings/volumes',
    'tags' => ['user_creatable_servers'],
]);

// Game ports only listen on this machine.
foreach (range(25565, 25574) as $port) {
    App\Models\Allocation::query()->firstOrCreate(['node_id' => $node->id, 'ip' => '127.0.0.1', 'port' => $port]);
}

$url = getenv('EGG_URL');
$egg = App\Models\Egg::query()->where('update_url', $url)->first();

if (! $egg) {
    dispatch_sync(new App\Jobs\InstallEgg($url));
    $egg = App\Models\Egg::query()->where('update_url', $url)->first() ?? App\Models\Egg::query()->latest('id')->first();
}

echo "EGG={$egg->name}\n";
Illuminate\Support\Env::writeVariables(['PROVISIONER_ALLOWED_EGGS' => (string) $egg->id], app()->environmentFilePath(), true);
PHP
)"

echo "Writing the Wings configuration..."
node_id="$(docker compose exec -T panel php artisan tinker --execute="echo App\\Models\\Node::query()->where('name', 'Local')->value('id');")"
node_id="$(printf '%s' "$node_id" | tr -cd '0-9')"
# Wings creates its own network for servers, so its range must not overlap one that already exists.
subnets="$(docker network ls -q | xargs docker network inspect --format '{{if ne .Name "pelican-provisioner-dev-servers"}}{{range .IPAM.Config}}{{.Subnet}} {{end}}{{end}}')"
# shellcheck disable=SC2086
docker compose exec -T panel php artisan p:node:configuration "$node_id" |
  docker compose exec -T -e STACK_DIR="$PWD" -e WINGS_SUBNET="${WINGS_SUBNET:-}" panel php /var/www/html/plugins/pelican-provisioner/docker/wings-config.php $subnets > wings/etc/config.yml.new
mv wings/etc/config.yml.new wings/etc/config.yml

echo "Starting Wings..."
docker compose up -d --force-recreate wings

echo
echo "Panel:  $panel_url"
echo "Admin:  admin@example.com / $admin_password"
echo "Friend: friend@example.com / $friend_password"
echo "The friend account has a quota, so the create-server page is available after login."
echo "To open a server console, add '127.0.0.1 wings' to your hosts file."
