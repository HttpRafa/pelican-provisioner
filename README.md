# Pelican Provisioner

Users with a quota can create, resize, and optionally delete their own servers. CPU, memory, disk, and server count stay inside the limits an administrator sets. Subusers cannot spend or change someone else's quota.

Author: [HttpRafa](https://github.com/HttpRafa). Version 0.1.0. It loads on Pelican 1.0.0-rc.2 and later 1.x releases. The caret starts at the release candidate because PHP treats `1.0.0-rc.2` as older than `1.0.0`, so a requirement of `^1.0.0` would refuse the panel this plugin was built against.

The folder installed in the panel must be named `pelican-provisioner`. That name is the plugin id. `plugin.json` lists the admin, app, and server panels because those are the only panels this code registers with. It does not ask the panel to install extra Composer packages. `meta.status` stays `not_installed` in git so a fresh copy is not treated as already installed. The panel writes that block when you install or enable it.

Coming from the official user-creatable-servers plugin: [MIGRATE.md](MIGRATE.md). To report a security problem: [SECURITY.md](SECURITY.md).

## Install

Import the zip from the [releases page](https://github.com/HttpRafa/pelican-provisioner/releases) from the plugin list, or copy this directory to `plugins/pelican-provisioner` inside the panel. Then install it from the plugin list or with `php artisan p:plugin:install`. Rebuild the zip from a git checkout with PHP and its zip extension:

```bash
composer build
```

The archive lands in `dist/` and is not committed. It contains the files the panel loads, not the test suite or the local Wings stack. Uninstalling the plugin rolls back its migration, which deletes every quota.

## Configure

Tag the public nodes that should receive these servers with `user_creatable_servers`, or change the tag in the plugin settings. An empty node-tag list uses every public node.

No egg is available until you choose it under Allowed eggs in the plugin settings, so an empty list means nobody can create a server. Allow every egg is a separate switch, because anyone who runs a server can read that egg's hidden variables from inside it, and because it also covers eggs added later. Keep eggs with privileged install scripts off the list unless you intend to allow them.

An empty port list uses any free allocation on a matching node. A server is never created without an allocation. If none is free, creation fails and says so.

Create a quota for each user who should be able to provision a server. Root administrators can do that immediately. Other administrator roles need the new Quota permissions. They cannot manage a root administrator's quota, and they only see email addresses if they can also list users.

The quota form has no default values, so every limit is a deliberate choice. `0` on a user's CPU, memory, or disk quota means that resource is unlimited, and a server created with `0` does not count against the node's capacity. A finite quota cannot be turned into an unlimited server. `0` on the server limit means there is no cap on how many servers the user can own. `0` on databases, allocations, backups, or subdomains means the created server cannot add any of that feature.

## What it checks

Creation and resizing read the user's servers with one indexed aggregate on `servers.owner_id`. They do not load those servers into PHP. The same request is serialized per user, then checked again after the server exists. A second request that would pass the quota is deleted. Startup values are accepted only for egg variables marked both viewable and editable. A startup field's validation rules come from the database, not from the browser. The egg allowlist is enforced in PHP, not only in the form, and the variables of an egg outside the list are never sent. Only the server owner can open the resize page or delete from it. A server that is suspended, still installing, or being transferred cannot be resized or deleted from here.

Resizing checks the node as well as the quota, so a server cannot grow past what the node allows. Keeping or reducing a size is always possible, even for an owner who is over quota.

If a node answers a create with an HTTP error, the half-created server is removed and the quota is free again. If it answers a delete with an HTTP error, the panel keeps the server and reports the failure. A 404 counts as already gone.

Creating is limited to 10 attempts per user in 10 minutes, and resizing and deleting to 30 together. The activity log records each create, resize, and delete with its sizes, including the old and new values of a resize. Refused requests go to the panel log as `pelican-provisioner.denied`.

The admin quota list is paginated and does not count every row on each page. User search is prefix search and does not preload the user table. The navigation badge is cached. Quota math for a user is one query, which stays cheap even when the panel has a large number of users, because it never scans users who are not part of that request.

Settings are rejected instead of written when they contain line breaks, invalid ports, a `$` in a tag, or a corrupt list that would otherwise fall back to "allow every node". A missing or corrupt stored list turns creation off. The string `false` stays false.

## Local panel

`docker/compose.yml` runs the panel on SQLite and Wings beside it. It is for local testing only: Wings gets the Docker socket, debug output is on, and both services listen on this machine only. From `docker/`:

```bash
./bootstrap.sh
```

The panel is at [http://127.0.0.1:8088](http://127.0.0.1:8088). The script seeds the panel, installs the plugin, and creates a node, one allowed egg, and two accounts. It generates their passwords and prints them when it finishes.

| Account | What you see |
| --- | --- |
| `admin@example.com` | Admin area, including Quotas |
| `friend@example.com` | Server list with Create server |

The plugin directory is mounted into the container, so the panel can write its install state into `plugin.json`.

## Development

PHP 8.4 or 8.5, Composer, Laravel Pint, Pest, and PHPStan with Larastan:

```bash
composer install
composer check
```

`composer check` covers `src/Domain`. The rest of the plugin needs a real panel, so the integration suite installs the built archive into the panel image with MariaDB and a stand-in for Wings. It covers creating, resizing, deleting, the forms, the admin permissions, and the settings. It needs Docker and publishes nothing to the host. Run `composer install` first to include static analysis of all of `src`:

```bash
sh tests/Integration/run.sh
```

Redis is the right cache for the rate limiter once more than one panel node is serving traffic. The quota queries themselves do not depend on the cache.
