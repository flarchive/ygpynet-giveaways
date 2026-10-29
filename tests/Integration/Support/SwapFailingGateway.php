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
 * Rebinds PointsGateway to the award-failing variant — applied AFTER
 * SwapPointsGateway so it wins the last singleton binding.
 */
class SwapFailingGateway implements ExtenderInterface
{
    public function extend(Container $container, ?Extension $extension = null): void
    {
        $container->singleton(PointsGateway::class, FailingAwardGateway::class);
    }
}
