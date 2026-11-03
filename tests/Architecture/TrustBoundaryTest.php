<?php

declare(strict_types=1);

it('keeps validation rules and editability out of browser state', function () {
    $page = (string) file_get_contents(dirname(__DIR__, 2).'/src/Filament/App/Pages/CreateServerPage.php');

    expect($page)->not->toContain('fromForm(')
        ->and($page)->not->toContain("Hidden::make('rules')")
        ->and($page)->not->toContain("Hidden::make('user_editable')")
        ->and($page)->not->toContain("Hidden::make('default_value')");
});
