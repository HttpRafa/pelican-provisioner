<?php

declare(strict_types=1);

it('does not load whole tables or trust the environment outside config', function () {
    $sources = '';

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
        dirname(__DIR__, 2).'/src',
        FilesystemIterator::SKIP_DOTS,
    ));

    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $sources .= file_get_contents($file->getPathname());
    }

    expect($sources)->not->toContain('Egg::all(')
        ->and($sources)->not->toContain('User::all(')
        ->and($sources)->not->toContain('Server::all(')
        ->and($sources)->not->toContain('->preload()')
        ->and($sources)->not->toContain('servers->sum')
        ->and($sources)->not->toContain('env(');
});
