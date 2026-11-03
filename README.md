# Pelican Provisioner

Users with a quota can create, resize, and optionally delete their own servers. CPU, memory, disk, and server count stay inside the limits an administrator sets. Subusers cannot spend or change someone else's quota.

The folder installed in the panel must be named `pelican-provisioner`. That name is the plugin id.

## Install

Copy this directory to `plugins/pelican-provisioner` inside the panel, then install it from the plugin list or with `php artisan p:plugin:install`. Tag the public nodes that should receive these servers with `user_creatable_servers`, or change the tag in the plugin settings. Create a quota for each user who should be able to provision a server. Root administrators can do that immediately. Other administrator roles need the new Quota permissions.

Leave the egg allowlist empty only if every egg on the panel is safe for those users. Eggs with privileged install scripts should be left off the list unless you intend to allow them. An empty port list uses any free allocation. An empty node-tag list uses every public node.

`0` on a user's CPU, memory, or disk quota means that resource is unlimited. A finite quota cannot be turned into an unlimited server. `0` on databases, allocations, backups, or subdomains means the created server cannot add any of that feature. An empty server limit means there is no cap on how many servers the user can own.

## What it checks

Creation and resizing read the user's servers with one indexed aggregate on `servers.owner_id`. They do not load those servers into PHP. The same request is serialized per user, then checked again after the server exists. A second request that would pass the quota is deleted. Startup values are accepted only for egg variables marked both viewable and editable. The egg allowlist is enforced in PHP, not only in the form. Only the server owner can open the resize page or delete from it.

The admin quota list is paginated and does not count every row on each page. User search is prefix search and does not preload the user table. The navigation badge is cached. Quota math for a user is one query, which stays cheap even when the panel has a large number of users, because it never scans users who are not part of that request.

Settings are rejected instead of written when they contain line breaks, invalid ports, or a corrupt list that would otherwise fall back to "allow everything". The string `false` stays false.

## Development

PHP 8.4 or 8.5, Composer, Laravel Pint, Pest, and PHPStan with Larastan:

```bash
composer install
composer check
```

Redis is the right cache for the rate limiter once more than one panel node is serving traffic. The quota queries themselves do not depend on the cache.
