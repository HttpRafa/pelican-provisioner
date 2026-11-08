# Pelican Provisioner

Users can create, resize, and optionally delete their own servers. CPU, memory, disk, and server count stay inside limits an administrator sets. Subusers cannot spend or change someone else's quota.

The plugin loads on Pelican 1.0.0-rc.2 and later 1.x releases. The installed folder must be named `pelican-provisioner`.

Moving quotas from the official user-creatable-servers plugin, or from 0.3.0 of this plugin: [MIGRATE.md](MIGRATE.md). Security reports: [SECURITY.md](SECURITY.md).

## Install

Import the zip from the [releases page](https://github.com/HttpRafa/pelican-provisioner/releases), or copy this directory to `plugins/pelican-provisioner`. Then install it from the plugin list or with `php artisan p:plugin:install`.

The panel finds new releases through the update manifest on that page and offers them in the plugin list. An update keeps overrides, bans, and settings. Uninstalling drops every override and ban. Moving from 0.3.0 to 0.4.0 is covered in [MIGRATE.md](MIGRATE.md).

## Configure

Tag public nodes that should receive these servers with `user_creatable_servers`, or set another tag in the plugin settings. An empty tag list uses every public node.

No egg can be used until it is selected under Allowed eggs. An empty list means nobody can create a server. Allow every egg includes eggs added later. Anyone who runs a server can read that egg's hidden variables from inside it. Leave eggs with privileged install scripts off the list unless that is intentional.

A quota can change eggs and nodes for one user. Leave the mode unset to keep the global rule. Add includes eggs or nodes the global rule does not. Remove takes eggs or nodes away from that user only. Replace ignores the global rule and uses only the list on the quota. An empty replace list allows none. Replace can also allow every egg, including eggs added later.

An empty port list uses any free allocation on a matching node. Creation fails if none is free.

Save a default CPU, memory, disk, and server limit, or leave all four empty. A partial default is invalid and grants nothing. CPU, memory, and disk must be above 0. A server limit of 0 means no cap. Every user without an override uses a valid default, including existing accounts and accounts that register themselves. With no valid default, those users cannot create servers.

A row in Quotas replaces the default for that user. Deleting the row returns them to the default. With no valid default saved, they cannot create servers.

Ban a user from their user page or the Bans list to stop them creating, resizing, and deleting, even when a default or an override exists. The override stays. Lifting the ban restores it.

Root administrators can manage overrides and bans. Other roles need the Provisioner Quota and Provisioner Quota Ban permissions. They cannot manage a root administrator's override or ban, and they only see email addresses if they can also list users.

The override form starts empty, so every limit is a choice.

- `0` on CPU, memory, or disk means that resource is unlimited. A server created with `0` does not count against the node's capacity. A finite quota cannot create an unlimited server.
- `0` on the server limit means there is no cap on how many servers the user can own.

Databases, extra allocations, backups, and subdomains are separate limits in the plugin settings. They are copied onto each server a user creates. `0` means that server cannot add any of that feature. Subdomains appear only when the subdomains plugin is enabled.

Resizing is on by default. Deleting stays off until it is enabled in the plugin settings. Only the server owner can open the resources page or delete from it. A suspended server, one that is still installing, or one that is being transferred cannot be resized or deleted here. Keeping or reducing a size is allowed even when the owner is over quota. A resize cannot grow past what the node allows.

Creating is limited to 10 attempts per user in 10 minutes. Resizing and deleting share a limit of 30. If the panel runs on more than one machine, use Redis for the cache so that limit is shared.

## Local panel

`docker/compose.yml` runs the panel on SQLite with Wings beside it, for local testing only. Published ports listen on this machine only. From `docker/`:

```bash
./bootstrap.sh
```

The panel is at [http://127.0.0.1:8088](http://127.0.0.1:8088). The script seeds the panel, installs the plugin, and creates a node, the Redis 7 egg, and two accounts. It prints new passwords each time and can be run again on an existing stack. `admin@example.com` is a root admin. `friend@example.com` has an override and can open Create server.

To open a server console, add `127.0.0.1 wings` to your hosts file. `PANEL_PORT` (`8088`), `WINGS_PORT` (`18080`), `WINGS_SFTP_PORT` (`2022`), `WINGS_SUBNET` (`10.213.0.0/16`), and `EGG_URL` change the defaults. The Wings subnet must not overlap an existing Docker network.

`./teardown.sh` from `docker/` removes the stack, including the server containers Wings started. Server files stay in `docker/wings/volumes`. The plugin directory is mounted into the container, so the panel can write its install state into `plugin.json`.

## Development

PHP 8.4 or 8.5, Composer, Laravel Pint, Pest, and PHPStan with Larastan.

```bash
composer install
composer check
```

`composer check` covers `src/Domain`. `composer build` writes the install zip to `dist/`. That archive is not committed. The integration suite installs it into a panel image with MariaDB and a stand-in for Wings. It needs Docker and does not publish ports on the host.

```bash
sh tests/Integration/run.sh
```
