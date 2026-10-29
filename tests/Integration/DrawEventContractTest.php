<?php

/*
 * This file is part of ygpynet/giveaways.
 *
 * Licensed under the MIT license.
 */

namespace Ygpynet\Giveaways\Tests\Integration;

use Ygpynet\Giveaways\DrawService;
use Ygpynet\Giveaways\Event\GiveawayWasDrawn;
use Ygpynet\Giveaways\Giveaway;

/**
 * The public event contract (docs/DESIGN §3.1): GiveawayWasDrawn fires when —
 * and only when — THIS invocation actually claimed and performed the draw.
 * A worker that loses the race against the scheduler must stay silent, or
 * third-party listeners (webhooks, leaderboards, federation) double-count
 * a draw that never happened for them.
 */
class DrawEventContractTest extends GiveawayTestCase
{
    /** @var list<GiveawayWasDrawn> */
    protected array $events = [];

    protected function collectDrawEvents(): void
    {
        $this->app()->getContainer()
            ->make(\Illuminate\Contracts\Events\Dispatcher::class)
            ->listen(GiveawayWasDrawn::class, function (GiveawayWasDrawn $event): void {
                $this->events[] = $event;
            });
    }

    protected function draws(): DrawService
    {
        return $this->app()->getContainer()->make(DrawService::class);
    }

    protected function poolGiveaway(): Giveaway
    {
        $giveaway = $this->createGiveaway(['winner_count' => 2]);
        $this->forceEntry($giveaway->id, 2);
        $this->forceEntry($giveaway->id, 3);

        return $giveaway;
    }

    public function test_the_winning_draw_fires_exactly_one_event_with_the_winners(): void
    {
        $this->addMembers(2, 3);
        $this->bootWithGiveaways();
        $this->collectDrawEvents();

        $giveaway = $this->poolGiveaway();

        $this->draws()->draw(Giveaway::find($giveaway->id));

        $this->assertCount(1, $this->events);
        $this->assertCount(2, $this->events[0]->winnerUserIds);
    }

    public function test_a_draw_that_loses_the_race_fires_no_event_at_all(): void
    {
        $this->addMembers(2, 3);
        $this->bootWithGiveaways();
        $this->collectDrawEvents();

        $giveaway = $this->poolGiveaway();

        // Two workers loaded the active row; the second loses the claim.
        $mine = Giveaway::find($giveaway->id);
        $theirs = Giveaway::find($giveaway->id);

        $this->draws()->draw($mine);
        $this->draws()->draw($theirs);

        $this->assertCount(1, $this->events, 'only the worker that actually drew may announce it');
        $this->assertSame(Giveaway::STATUS_DRAWN, $this->events[0]->giveaway->status);
    }
}
