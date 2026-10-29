<?php

/*
 * This file is part of ygpynet/giveaways.
 *
 * Licensed under the MIT license.
 */

// Works both standalone (composer install inside this package) and when the
// package sits inside a Flarum site's vendor/ tree.
foreach ([__DIR__.'/../vendor/autoload.php', __DIR__.'/../../../autoload.php', __DIR__.'/../../autoload.php'] as $autoload) {
    if (file_exists($autoload)) {
        $loader = require $autoload;
        break;
    }
}

if (! isset($loader)) {
    fwrite(STDERR, "Could not find an autoloader. Run 'composer install' first.\n");
    exit(1);
}

$loader->addPsr4('Ygpynet\\Giveaways\\Tests\\', __DIR__);
