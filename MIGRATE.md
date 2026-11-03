# Migrate from user-creatable-servers

Uninstall the official plugin, then delete its quota table:

```sql
DROP TABLE IF EXISTS user_resource_limits;
```

The same statement works on MySQL, MariaDB, and SQLite.
