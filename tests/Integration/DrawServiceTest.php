<?php

/*
 * This file is part of ygpynet/giveaways.
 *
 * Licensed under the MIT license.
 */

namespace Ygpynet\Giveaways\Tests\Integration;

use Ygpynet\Giveaways\DrawService;
use Ygpynet\Giveaways\Giveaway;
use Ygpynet\Giveaways\GiveawayWinner;
use Ygpynet\Giveaways\Support\DrawVerifier;

/**
 * Locks down the draw invariants that protect the provably-fair promise:
 * the active → drawn move is claimed exactly once even when a manual draw
 * races the scheduler, and the published record always self-verifies.
 */
class DrawServiceTest extends GiveawayTestCase
{
    protected function drawService(): DrawService
    {
        return $this->app()->getContainer()->make(DrawService::class);
    }

    protected function poolGiveaway(): Giveaway
    {
        $g = $this->createGiveaway(['winner_count' => 2]);
        $this->forceEntry($g->id, 2, 5);
        $this->forceEntry($g->id, 3, 1);
        $this->forceEntry($g->id, 4, 2);

        return $g;
    }

    public function test_a_second_draw_with_a_stale_model_cannot_double_draw(): void
    {
        $this->addMembers(2, 3, 4);
        $this->bootWithGiveaways();

        $giveaway = $this->poolGiveaway();

        // Two "workers" load the same active row — exactly what a manual
        // "draw now" clicking concurrently with the scheduler looks like.
        $mine = Giveaway::find($giveaway->id);
        $theirs = Giveaway::find($giveaway->id);

        $this->drawService()->draw($mine);

        $seedAfterFirst = Giveaway::find($giveaway->id)->draw_seed;
        $winnersAfterFirst = GiveawayWinner::where('giveaway_id', $giveaway->id)->count();

        // The stale copy still believes it is 'active' in memory.
        $this->drawService()->draw($theirs);

        $this->assertSame(
            $winnersAfterFirst,
            GiveawayWinner::where('giveaway_id', $giveaway->id)->count(),
            'the second draw must be a total no-op'
        );
        $this->assertSame(
            $seedAfterFirst,
            Giveaway::find($giveaway->id)->draw_seed,
            'the published seed must never change after the first draw'
        );
        $this->assertSame(2, $winnersAfterFirst);
    }

    public function test_winners_are_distinct_members_of_the_entrant_pool(): void
    {
        $this->addMembers(2, 3, 4);
        $this->bootWithGiveaways();

        $giveaway = $this->poolGiveaway();
        $this->drawService()->draw($giveaway);

        $winnerIds = array_map('intval', GiveawayWinner::where('giveaway_id', $giveaway->id)
            ->orderBy('position')->pluck('user_id')->all());

        $this->assertCount(2, $winnerIds);
        $this->assertSame($winnerIds, array_unique($winnerIds), 'the same user must not win twice');
        foreach ($winnerIds as $uid) {
            $this->assertContains($uid, [2, 3, 4], 'winners must come from the entrant pool');
        }
    }

    public function test_a_completed_draw_verifies_against_its_own_published_data(): void
    {
        $this->addMembers(2, 3, 4);
        $this->bootWithGiveaways();

        $giveaway = $this->poolGiveaway();
        $this->drawService()->draw($giveaway);

        $report = $this->app()->getContainer()
            ->make(DrawVerifier::class)
            ->verify(Giveaway::find($giveaway->id));

        $this->assertTrue($report['ok'], implode('; ', $report['problems']));
        $this->assertTrue($report['hash_ok']);
        $this->assertTrue($report['winners_ok']);
    }

    public function test_draw_with_no_entries_still_completes_and_publishes_empty_fingerprint(): void
    {
        $this->bootWithGiveaways();

        $giveaway = $this->createGiveaway();
        $this->drawService()->draw($giveaway);

        $giveaway->refresh();

        $this->assertSame(Giveaway::STATUS_DRAWN, $giveaway->status);
        $this->assertNotEmpty($giveaway->draw_seed);
        // Hash of the empty entrant list — deterministic, verifiable, empty.
        $this->assertSame(hash('sha256', ''), $giveaway->entrant_hash);
        $this->assertSame(0, GiveawayWinner::where('giveaway_id', $giveaway->id)->count());
    }

    public function test_draw_of_a_never_running_or_double_givenaway_is_skipped(): void
    {
        $this->addMembers(2);
        $this->bootWithGiveaways();

        $draft = $this->createGiveaway(['status' => Giveaway::STATUS_DRAFT]);

        $this->drawService()->draw($draft);

        $this->assertSame(Giveaway::STATUS_DRAFT, Giveaway::find($draft->id)->status);
        $this->assertNull(Giveaway::find($draft->id)->draw_seed);
    }
}
