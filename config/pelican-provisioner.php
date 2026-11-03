<?php

// Stored as raw strings. Casting env('false') with (bool) is true in PHP, which would turn the delete switch on.

return [
    'database_limit' => env('PROVISIONER_DATABASE_LIMIT', '0'),
    'allocation_limit' => env('PROVISIONER_ALLOCATION_LIMIT', '0'),
    'backup_limit' => env('PROVISIONER_BACKUP_LIMIT', '0'),
    'subdomain_limit' => env('PROVISIONER_SUBDOMAIN_LIMIT', '0'),
    'can_users_update_servers' => env('PROVISIONER_CAN_USERS_UPDATE_SERVERS', 'true'),
    'can_users_delete_servers' => env('PROVISIONER_CAN_USERS_DELETE_SERVERS', 'false'),
    'deployment_tags' => env('PROVISIONER_DEPLOYMENT_TAGS', 'user_creatable_servers'),
    'deployment_ports' => env('PROVISIONER_DEPLOYMENT_PORTS', ''),
    'allowed_eggs' => env('PROVISIONER_ALLOWED_EGGS', ''),
    'allow_all_eggs' => env('PROVISIONER_ALLOW_ALL_EGGS', 'false'),
];
