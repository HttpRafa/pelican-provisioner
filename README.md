# Pelican Provisioner

Users with a quota can create, resize, and optionally delete their own servers. CPU, memory, disk, and server count stay inside limits an administrator sets. Subusers cannot spend or change someone else's quota.

The plugin loads on Pelican 1.0.0-rc.2 and later 1.x releases. The installed folder must be named `pelican-provisioner`.

This project is written with help from Grok 4.7 and Claude. Releases are tested and verified by the human maintainers.

From the official user-creatable-servers plugin: [MIGRATE.md](MIGRATE.md). Security reports: [SECURITY.md](SECURITY.md).

## Install

Import the zip from the [releases page](https://github.com/HttpRafa/pelican-provisioner/releases), or copy this directory to `plugins/pelican-provisioner`. Then install it from the plugin list or with `php artisan p:plugin:install`.

Rebuild the zip from a git checkout (PHP with the zip extension):

```bash
composer build
```

The archive is written to `dist/` and is not committed. Uninstalling the plugin rolls back its migration and deletes every override and ban.

## Configure

Tag public nodes that should receive these servers with `user_creatable_servers`, or set another tag in the plugin settings. An empty tag list uses every public node.

No egg can be used until it is selected under Allowed eggs. An empty list means nobody can create a server. Allow every egg is a separate switch: it includes eggs added later, and anyone who runs a server can read that egg's hidden variables from inside it. Leave eggs with privileged install scripts off the list unless that is intentional.

A quota can change those eggs and nodes for one user. Leave the mode unset to keep the global rule. Add includes eggs or nodes the global rule does not. Remove takes eggs or nodes away from that user only. Replace ignores the global rule and uses only the list on the quota, and an empty replace list allows none. Replace can also allow every egg, including eggs added later.

An empty port list uses any free allocation on a matching node. Creation fails if none is free.

Root administrators can manage overrides and bans immediately. Other roles need the Quota and Quota ban permissions. They cannot manage a root administrator's override or ban, and they only see email addresses if they can also list users.

Save one default CPU, memory, disk, and server limit in the plugin settings. Every user without an override uses it, including accounts that already exist and accounts that register themselves. Leave the default empty and those users cannot create servers. An empty default is not unlimited. CPU, memory, and disk in the default must be above 0. A server limit of 0 means no cap on how many servers they can own.

A row in Quotas replaces the default for that one user. Deleting the row returns the user to the default. It does not take self-service away. To block one user even when a default or an override exists, ban them. A ban leaves the override in place, and lifting the ban restores it.

The quota form has no defaults, so every limit on an override is a choice.

- `0` on CPU, memory, or disk means that resource is unlimited. A server created with `0` does not count against the node's capacity. A finite quota cannot create an unlimited server.
- `0` on the server limit means there is no cap on how many servers the user can own.
- `0` on databases, allocations, backups, or subdomains means the created server cannot add any of that feature.

Only the server owner can open the resources page or delete from it. A suspended server, one that is still installing, or one that is being transferred cannot be resized or deleted here. Keeping or reducing a size is allowed even when the owner is over quota. A resize cannot grow past what the node allows.

Creating is limited to 10 attempts per user in 10 minutes. Resizing and deleting share a limit of 30. If the panel runs on more than one machine, use Redis for the cache so that limit is shared.

## Local panel

`docker/compose.yml` runs the panel on SQLite with Wings beside it, for local testing only. Wings receives the Docker socket, debug output is on, and every published port listens on this machine only. From `docker/`:

```bash
./bootstrap.sh
```

The panel is at [http://127.0.0.1:8088](http://127.0.0.1:8088). The script seeds the panel, installs the plugin, and creates a node with ten ports, the Redis 7 egg, and two accounts. It prints new passwords each time and can be run again on an existing stack.

| Account | Access |
| --- | --- |
| `admin@example.com` | Admin area, including Quotas |
| `friend@example.com` | Server list with Create server |

Server files are written to `docker/wings/` as your user. To open a server console in the browser, add `127.0.0.1 wings` to your hosts file. These variables change the defaults:

| Variable | Default | Purpose |
| --- | --- | --- |
| `PANEL_PORT` | `8088` | Panel address on this machine |
| `WINGS_PORT` | `18080` | Wings API and console |
| `WINGS_SFTP_PORT` | `2022` | SFTP |
| `WINGS_SUBNET` | `10.213.0.0/16` | Network for server containers. It must not overlap an existing Docker network. |
| `EGG_URL` | Redis 7 | Egg the friend account can use |

`./teardown.sh` from `docker/` removes the stack, including the server containers Wings started, which `docker compose down` does not know about. Server files stay in `docker/wings/volumes` until you delete them.

The plugin directory is mounted into the container, so the panel can write its install state into `plugin.json`.

## Development

PHP 8.4 or 8.5, Composer, Laravel Pint, Pest, and PHPStan with Larastan.

```bash
composer install
composer check
```

`composer check` covers `src/Domain`. The rest of the plugin needs a panel, so the integration suite installs the built archive into a panel image with MariaDB and a stand-in for Wings. It needs Docker and does not publish ports on the host.

```bash
sh tests/Integration/run.sh
```
