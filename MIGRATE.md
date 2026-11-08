# Migrate from user-creatable-servers

Upgrading this plugin from 0.3.0 is an uninstall, then an install of the new zip. Uninstall drops `provisioner_quotas` and `provisioner_quota_bans`. The new install creates both empty. Rows from 0.3.0 are not imported. Enter overrides and bans again after install. The default from 0.3.0 is not read, because it only applied to new accounts and now applies to every user without an override. Save a default in the plugin settings when you want that. Other plugin settings stay in the environment file and keep working.

The steps below copy quotas from the official user-creatable-servers plugin onto a fresh install. Those copied rows are overrides. Users without a copied row use the saved default.

Copy the quotas before you uninstall the official plugin. Uninstalling it rolls back its migration, which deletes its table.

1. Disable the official plugin from the plugin list. Do not uninstall it yet. Both plugins use the same page address, so they cannot run at the same time.
2. Install this plugin.
3. Copy the quotas. The statement works on MySQL, MariaDB, and SQLite, and it skips users who already have a quota here.

   ```sql
   INSERT INTO provisioner_quotas (user_id, cpu, memory, disk, server_limit, created_at, updated_at)
   SELECT user_id, cpu, memory, disk, server_limit, created_at, updated_at
   FROM user_resource_limits
   WHERE user_id NOT IN (SELECT user_id FROM provisioner_quotas);
   ```

4. Check the quota list in the admin area. A server limit of `0` or empty means no cap in both plugins.
5. Uninstall the official plugin. If its table is still there, remove it.

   ```sql
   DROP TABLE IF EXISTS user_resource_limits;
   ```

6. Open this plugin's settings and enter your values again. The official plugin's `UCS_*` environment values are not read. An empty egg list allowed every egg in the official plugin and allows none here, so choose your eggs or turn on Allow every egg.
