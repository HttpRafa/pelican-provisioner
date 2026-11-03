<?php

declare(strict_types=1);

// Builds the archive the panel imports. It holds the files the panel loads, not the tests or the local stack.

if (!class_exists(ZipArchive::class)) {
    fwrite(STDERR, "The PHP zip extension is required.\n");

    exit(1);
}

$root = dirname(__DIR__);
$plugin = json_decode((string) file_get_contents($root.'/plugin.json'), true, flags: JSON_THROW_ON_ERROR);
$id = $plugin['id'];

// A local panel writes its install state into plugin.json. A fresh import must not inherit that.
$plugin['meta'] = ['status' => 'not_installed'];

$target = $root.'/dist/'.$id.'-'.$plugin['version'].'.zip';

if (!is_dir(dirname($target))) {
    mkdir(dirname($target), recursive: true);
}

$zip = new ZipArchive();

if ($zip->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "Could not create $target.\n");

    exit(1);
}

$zip->addFromString($id.'/plugin.json', json_encode($plugin, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

foreach (['composer.json', 'LICENSE', 'README.md', 'MIGRATE.md', 'SECURITY.md'] as $file) {
    $zip->addFile($root.'/'.$file, $id.'/'.$file);
}

foreach (['src', 'config', 'database', 'lang'] as $directory) {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory, FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        if ($file->isFile()) {
            $zip->addFile($file->getPathname(), $id.'/'.substr($file->getPathname(), strlen($root) + 1));
        }
    }
}

$zip->close();

echo $target."\n";
