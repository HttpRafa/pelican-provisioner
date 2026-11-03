<?php

declare(strict_types=1);

require __DIR__.'/bootstrap.php';

use App\Models\Server;

$alice = as_user('alice');

check('run '.$argv[1].': exactly one server exists', Server::query()->where('owner_id', $alice->id)->count(), 1);
check('run '.$argv[1].': usage stays inside the quota', (int) Server::query()->where('owner_id', $alice->id)->sum('cpu'), 150);

reset_state();

if ($GLOBALS['failed'] > 0) {
    finish();
}
