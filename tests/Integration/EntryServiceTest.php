<?php

/*
 * This file is part of ygpynet/giveaways.
 *
 * Licensed under the MIT license.
 */

namespace Ygpynet\Giveaways\Tests\Integration;

use Ygpynet\Giveaways\DrawService;
use Ygpynet\Giveaways\EntryService;
use Ygpynet\Giveaways\Exception\GiveawayClosedException;
use Ygpynet\Giveaways\Giveaway;
use Ygpynet\Giveaways\GiveawayEntry;
use Ygpynet\Giveaways\GiveawayRefund;
use Ygpynet\Giveaways\Tests\Integration\Support\MemoryPointsGateway;
use Flarum\User\User;

/**
 * Entry-time invariants: the locked re-check must reject writes racing a
 * draw (the "ghost entry" that would corrupt the published entrant hash),
 * money must never be lost without an entry, and bonus awards must not
 * clobber each other.
 */
class EntryServiceTest extends GiveawayTestCase
{
    protected function entries(): EntryService
    {
        return $this->app()->getContainer()->make(EntryService::class);
    }

    public function test_an_entry_racing_a_committed_draw_is_rejected_and_never_written(): void
    {
        $this->addMembers(2, 3);
        $this->bootWithGiveaways();

        $giveaway = $this->createGiveaway();
        $this->forceEntry($giveaway->id, 3);

        // The scheduler draws while user 2's request is mid-flight: the
        // controller loaded $giveaway while it was still active.
        $stale = Giveaway::find($giveaway->id);
        $this->app()->getContainer()->make(DrawService::class)->draw(Giveaway::find($giveaway->id));

        $this->expectException(GiveawayClosedException::class);

        try {
            $this->entries()->enter($stale, User::find(2));
        } finally {
            $this->assertSame(
                1,
                GiveawayEntry::where('giveaway_id', $giveaway->id)->count(),
                'the ghost entry must not exist in the drawn giveaway'
            );
        }
    }

    public function test_a_rejected_paid_entry_refunds_the_fee_immediately(): void
    {
        $this->addMembers(2, 3);
        $this->bootWithGiveaways();

        $giveaway = $this->createGiveaway([], ['entry_cost_points' => 5]);
        $this->forceEntry($giveaway->id, 3);

        $stale = Giveaway::find($giveaway->id);
        $this->app()->getContainer()->make(DrawService::class)->draw(Giveaway::find($giveaway->id));

        try {
            $this->entries()->enter($stale, User::find(2));
            $this->fail('expected GiveawayClosedException');
        } catch (GiveawayClosedException $e) {
            // expected
        }

        $ops = MemoryPointsGateway::$ledger;
        $this->assertCount(2, $ops);
        $this->assertSame('deduct', $ops[0]['op']);
        $this->assertSame('award', $ops[1]['op']);
        $this->assertSame(5, $ops[1]['amount'], 'the user must get the fee back');
        $this->assertSame(0, GiveawayRefund::pending()->count(), 'the immediate refund landed, nothing to queue');
    }

    public function test_entering_twice_is_idempotent(): void
    {
        $this->addMembers(2);
        $this->bootWithGiveaways();

        $giveaway = $this->createGiveaway();
        $user = User::find(2);

        $first = $this->entries()->enter($giveaway, $user);
        $second = $this->entries()->enter($giveaway, $user);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, GiveawayEntry::where('giveaway_id', $giveaway->id)->count());
    }

    public function test_paid_entry_records_the_charged_amount_on_the_entry(): void
    {
        $this->addMembers(2);
        $this->bootWithGiveaways();

        $giveaway = $this->createGiveaway([], ['entry_cost_points' => 30]);
        $entry = $this->entries()->enter($giveaway, User::find(2));

        $this->assertSame(30, (int) $entry->paid_amount, 'fund audit: the entry records what was actually paid');
        $this->assertSame(70, MemoryPointsGateway::balance(2));
    }

    public function test_insufficient_balance_blocks_entry_without_writing_anything(): void
    {
        $this->addMembers(2);
        $this->bootWithGiveaways();

        MemoryPointsGateway::seed(2, 10);

        $giveaway = $this->createGiveaway([], ['entry_cost_points' => 30]);
        $user = User::find(2);

        $this->assertNotNull($this->entries()->ineligibleReason($giveaway, $user));

        $this->expectException(\DomainException::class);

        try {
            $this->entries()->enter($giveaway, $user);
        } finally {
            $this->assertSame(0, GiveawayEntry::where('giveaway_id', $giveaway->id)->count());
        }
    }

    public function test_bonus_is_awarded_once_per_source_and_totals_sum(): void
    {
        $this->addMembers(2);
        $this->bootWithGiveaways();

        $giveaway = $this->createGiveaway();
        $user = User::find(2);
        $this->entries()->enter($giveaway, $user);

        $service = $this->entries();
        $service->addBonus($giveaway, $user, 'post', 2);
        $service->addBonus($giveaway, $user, 'post', 2); // duplicate — ignored
        $service->addBonus($giveaway, $user, 'referral', 3);

        $entry = GiveawayEntry::where('giveaway_id', $giveaway->id)->where('user_id', 2)->first();

        $this->assertSame(6, (int) $entry->entries, 'base 1 + post 2 + referral 3');
        $this->assertSame(['base' => 1, 'post' => 2, 'referral' => 3], $entry->sourcesArray());
    }

    public function test_bonus_skips_users_who_never_entered(): void
    {
        $this->addMembers(2);
        $this->bootWithGiveaways();

        $giveaway = $this->createGiveaway();
        $this->entries()->addBonus($giveaway, User::find(2), 'post', 5);

        $this->assertSame(0, GiveawayEntry::where('giveaway_id', $giveaway->id)->count());
    }

    public function test_eligibility_gates_flow_from_the_tagged_rule_set(): void
    {
        $this->addMembers(2);
        $this->bootWithGiveaways();

        $this->database()->table('users')->where('id', 2)->update(['comment_count' => 1]);
        $user = User::find(2);

        $strict = $this->createGiveaway([], ['min_posts' => 5]);
        $open = $this->createGiveaway();

        $service = $this->entries();

        $this->assertNotNull($service->ineligibleReason($strict, $user), 'MinPostsRule must fire');
        $this->assertNull($service->ineligibleReason($open, $user), 'no rules block the open giveaway');
    }

    /**
     * Bug isolation (regression): a post-bonus award that races a committed
     * draw must never mutate entry weights afterwards — the published
     * entrant_hash would no longer describe the pool, and giveaways:verify
     * would flag an honest draw as tampered.
     */
    public function test_a_bonus_award_racing_a_committed_draw_never_mutates_the_published_pool(): void
    {
        $this->addMembers(2, 3);
        $this->bootWithGiveaways();

        $giveaway = $this->createGiveaway(['winner_count' => 1]);
        $user = User::find(2);
        $this->entries()->enter($giveaway, $user);
        $this->forceEntry($giveaway->id, 3);

        // AwardPostBonus loaded this model while the giveaway was active...
        $stale = Giveaway::find($giveaway->id);

        // ...the scheduler drew in the meantime, publishing the hash.
        $this->app()->getContainer()->make(DrawService::class)->draw(Giveaway::find($giveaway->id));

        // The listener now calls addBonus() with its stale copy.
        $this->entries()->addBonus($stale, $user, 'post', 3);

        $entry = GiveawayEntry::where('giveaway_id', $giveaway->id)->where('user_id', 2)->first();
        $this->assertSame(1, (int) $entry->entries, 'a post-draw bonus award must be skipped, not applied');

        $report = $this->app()->getContainer()
            ->make(\Ygpynet\Giveaways\Support\DrawVerifier::class)
            ->verify(Giveaway::find($giveaway->id));

        $this->assertTrue($report['ok'], 'the draw must stay verifiable: ' . implode('; ', $report['problems']));
    }
}
