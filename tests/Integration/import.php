<?php

declare(strict_types=1);

// Imports the built archive through the panel's own code, so a packaging mistake fails here.

require __DIR__.'/bootstrap.php';

use App\Services\Helpers\PluginService;
use Illuminate\Http\UploadedFile;

$archive = glob(dirname(__DIR__, 2).'/dist/*.zip')[0] ?? null;

if ($archive === null) {
    fwrite(STDERR, "No archive in dist/.\n");

    exit(1);
}

echo 'Imported '.app(PluginService::class)->downloadPluginFromFile(new UploadedFile($archive, basename($archive), 'application/zip', null, true))."\n";
