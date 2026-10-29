<?php

/*
 * This file is part of ygpynet/giveaways.
 *
 * Licensed under the MIT license.
 */

namespace Ygpynet\Giveaways\Tests\Integration\Support;

use Flarum\Extension\Extension;
use Flarum\Extend\ExtenderInterface;
use Illuminate\Contracts\Container\Container;
use Ygpynet\Giveaways\Contract\PointsGateway;

/**
 * Rebinds the PointsGateway port to {@see MemoryPointsGateway} for the test
 * forum — exactly the swap a real site would make to integrate another
 * points system, so the test also exercises the extension point itself.
 */
class SwapPointsGateway implements ExtenderInterface
{
    public function extend(Container $container, ?Extension $extension = null): void
    {
        $container->singleton(PointsGateway::class, MemoryPointsGateway::class);
    }
}
