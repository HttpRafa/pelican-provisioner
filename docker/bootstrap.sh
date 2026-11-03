#!/bin/sh
# Seeds a local panel so the plugin can be clicked through. It is not part of a panel install.
set -eu

cd "$(dirname "$0")"

admin_password="$(head -c 18 /dev/urandom | base64 | tr -dc 'A-Za-z0-9')"
friend_password="$(head -c 18 /dev/urandom | base64 | tr -dc 'A-Za-z0-9')"

echo "Starting the panel..."
docker compose up -d panel

echo "Waiting until the panel answers..."
i=0
until curl -fsS -o /dev/null http://127.0.0.1:8088/installer || curl -fsS -o /dev/null http://127.0.0.1:8088/admin/login; do
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
docker compose exec -T panel php artisan p:user:make --email=admin@example.com --username=admin --password="$admin_password" --admin=1
docker compose exec -T panel php artisan p:user:make --email=friend@example.com --username=friend --password="$friend_password"

echo "Installing the plugin..."
# The panel writes install state into plugin.json. The bind mount is owned by the host user.
chmod a+w ../plugin.json
docker compose exec -T panel php artisan p:plugin:install pelican-provisioner

echo "Creating a quota, a node, and one egg..."
docker compose exec -T panel php artisan tinker --execute="$(cat <<'PHP'
$admin = App\Models\User::query()->where('email', 'admin@example.com')->first();
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

if (! App\Models\Node::query()->where('name', 'Local')->exists()) {
    $node = App\Models\Node::query()->create([
        'name' => 'Local',
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
        'daemon_listen' => 8080,
        'daemon_connect' => 8080,
        'daemon_sftp' => 2022,
        'daemon_sftp_alias' => null,
        'daemon_base' => '/var/lib/pelican/volumes',
        'tags' => ['user_creatable_servers'],
    ]);
    echo "NODE_ID={$node->id}\n";
} else {
    echo 'NODE_ID='.App\Models\Node::query()->where('name', 'Local')->value('id')."\n";
}

if (App\Models\Egg::query()->doesntExist()) {
    Artisan::call('p:egg:update-index');
    $index = cache()->get('eggs.index', []);
    $url = null;
    foreach ($index as $eggs) {
        if (is_array($eggs) && $eggs !== []) {
            $url = array_key_first($eggs);
            break;
        }
    }
    if (is_string($url) && $url !== '') {
        dispatch_sync(new App\Jobs\InstallEgg($url));
        echo "EGG={$url}\n";
    }
}

$egg = App\Models\Egg::query()->orderBy('id')->first();

if ($egg) {
    Illuminate\Support\Env::writeVariables(['PROVISIONER_ALLOWED_EGGS' => (string) $egg->id], app()->environmentFilePath(), true);
}
PHP
)"

mkdir -p wings/etc
node_id="$(docker compose exec -T panel php artisan tinker --execute="echo App\\Models\\Node::query()->where('name', 'Local')->value('id');")"
node_id="$(printf '%s' "$node_id" | tr -cd '0-9')"
docker compose exec -T panel php artisan p:node:configuration "$node_id" > wings/etc/config.yml
# Wings calls the panel over the compose network. The browser keeps using APP_URL.
sed -i 's#^remote: .*#remote: http://panel#' wings/etc/config.yml

echo "Starting Wings..."
docker compose up -d wings

echo
echo "Panel:  http://127.0.0.1:8088"
echo "Admin:  admin@example.com / $admin_password"
echo "Friend: friend@example.com / $friend_password"
echo "The friend account has a quota, so the create-server page is available after login."
