<?php

declare(strict_types=1);

it('defines every translation key the package refers to', function () {
    $missing = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/src')) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        preg_match_all("/'filagora-cms::([a-z_]+\.[a-z0-9_.]+)'/", file_get_contents($file->getPathname()), $found);

        foreach ($found[1] as $key) {
            if (! trans()->has('filagora-cms::'.$key, 'en')) {
                $missing[] = $key;
            }
        }
    }

    expect($missing)->toBe([]);
});
