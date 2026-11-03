<?php

declare(strict_types=1);

// One of several parallel create calls by the same user. Only one of them fits the quota.

require __DIR__.'/bootstrap.php';

use App\Models\Egg;

as_user('alice');
config()->set('pelican-provisioner.allowed_eggs', (string) Egg::query()->where('name', 'Audit Allowed')->value('id'));

usleep(max(0, ((int) $argv[1] - (int) (microtime(true) * 1000)) * 1000));

echo '  process '.$argv[2].': '.outcome(fn () => create_server('race'.$argv[2], (int) Egg::query()->where('name', 'Audit Allowed')->value('id'), cpu: 150))."\n";
