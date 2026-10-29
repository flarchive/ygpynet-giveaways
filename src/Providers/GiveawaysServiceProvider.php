<?php

namespace Ygpynet\Giveaways\Providers;

use Ygpynet\Giveaways\CancelService;
use Ygpynet\Giveaways\Contract\EligibilityRule;
use Ygpynet\Giveaways\Contract\PointsGateway;
use Ygpynet\Giveaways\Contract\WinnerPicker;
use Ygpynet\Giveaways\EntryService;
use Ygpynet\Giveaways\RefundService;
use Ygpynet\Giveaways\Support\Eligibility\MinAccountAgeRule;
use Ygpynet\Giveaways\Support\Eligibility\MinPostsRule;
use Ygpynet\Giveaways\Support\Eligibility\PointsBalanceRule;
use Ygpynet\Giveaways\Support\DrawVerifier;
use Ygpynet\Giveaways\Support\HashWeightedPicker;
use Ygpynet\Giveaways\Support\RamonPointSystemGateway;
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

        // Money movement goes through exactly one service so failed refunds
        // always land in the retry queue instead of only in the log.
        $this->container->singleton(RefundService::class);
        $this->container->singleton(CancelService::class);

        // Fair-draw verification shares the bound WinnerPicker, so `giveaways:
        // verify` always recomputes with the same algorithm that drew.
        $this->container->singleton(DrawVerifier::class, fn ($c) => new DrawVerifier($c->make(WinnerPicker::class)));

        // Eligibility gates are a TAGGED set: third-party extensions add their
        // own with `$container->tag(MyRule::class, EligibilityRule::class)`.
        // Order matters for message priority and matches the historical checks
        // (min posts → min age → points balance).
        $this->container->singleton(MinPostsRule::class);
        $this->container->singleton(MinAccountAgeRule::class);
        $this->container->singleton(PointsBalanceRule::class);
        $this->container->tag(
            [MinPostsRule::class, MinAccountAgeRule::class, PointsBalanceRule::class],
            EligibilityRule::class
        );

        // EntryService can't be auto-wired: its rule list comes from the tag.
        // The structural wiring below keeps the constructor's other deps
        // resolving exactly as Illuminate would have done them.
        $this->container->singleton(EntryService::class, fn ($c) => new EntryService(
            $c->make(\Flarum\Locale\TranslatorInterface::class),
            $c->make(\Illuminate\Database\ConnectionInterface::class),
            $c->make(PointsGateway::class),
            $c->make(\Illuminate\Contracts\Events\Dispatcher::class),
            $c->make(RefundService::class),
            iterator_to_array($c->tagged(EligibilityRule::class))
        ));
    }
}
