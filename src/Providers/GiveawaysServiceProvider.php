<?php

namespace ErnestDefoe\Giveaways\Providers;

use ErnestDefoe\Giveaways\Contract\PointsGateway;
use ErnestDefoe\Giveaways\Contract\WinnerPicker;
use ErnestDefoe\Giveaways\Support\HashWeightedPicker;
use ErnestDefoe\Giveaways\Support\RamonPointSystemGateway;
use Flarum\Foundation\AbstractServiceProvider;

class GiveawaysServiceProvider extends AbstractServiceProvider
{
    public function register(): void
    {
        // Interface → default implementation. Other extensions may rebind either
        // one to integrate an alternative points system or draw algorithm
        // without touching this extension's code.
        $this->container->singleton(PointsGateway::class, RamonPointSystemGateway::class);
        $this->container->singleton(WinnerPicker::class, HashWeightedPicker::class);
    }
}
