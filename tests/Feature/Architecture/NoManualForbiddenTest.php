<?php

use Symfony\Component\Finder\Finder;

// AGENTS.md §7.2: permission denials go through Policies (`authorize`/`can`),
// never `abort(403)`, so the AccessDeniedHttpException hook audits them (FND-022).

it('FND-022 finds no manual 403 abort in app/', function () {
    $offenders = [];

    foreach (Finder::create()->files()->in(app_path())->name('*.php') as $file) {
        $matched = preg_match(
            '/\babort(?:_if|_unless)?\s*\([^;]*?(?:\b403\b|HTTP_FORBIDDEN)/s',
            $file->getContents(),
        );

        if ($matched === 1) {
            $offenders[] = $file->getRelativePathname();
        }
    }

    expect($offenders)->toBe([]);
});
