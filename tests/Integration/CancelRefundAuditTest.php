<?php

/*
 * This file is part of ygpynet/giveaways.
 *
 * Licensed under the MIT license.
 */

namespace Ygpynet\Giveaways\Tests\Integration;

use Ygpynet\Giveaways\CancelService;
use Ygpynet\Giveaways\EntryService;
use Ygpynet\Giveaways\Giveaway;
use Ygpynet\Giveaways\GiveawayRefund;
use Ygpynet\Giveaways\RefundService;
use Ygpynet\Giveaways\Tests\Integration\Support\FailingAwardGateway;
use Ygpynet\Giveaways\Tests\Integration\Support\SwapFailingGateway;
use Ygpynet\Giveaways\Tests\Integration\Support\MemoryPointsGateway;
use Flarum\User\User;

/**
 * The fund-audit trail: cancellation refunds what each entrant ACTUALLY paid
 * (not the possibly-edited current fee), and a failed money movement is
 * never lost — it lands in the durable retry queue.
 */
class CancelRefundAuditTest extends GiveawayTestCase
{
    protected function cancelService(): CancelService
    {
        return $this->app()->getContainer()->make(CancelService::class);
    }

    public function test_cancelling_refunds_each_entrants_recorded_payment_not_the_current_fee(): void
    {
        $this->addMembers(2, 3);
        $this->bootWithGiveaways();

        $giveaway = $this->createGiveaway([], ['entry_cost_points' => 40]);

        $entries = $this->app()->getContainer()->make(EntryService::class);
        $entries->enter($giveaway, User::find(2));
        $entries->enter($giveaway, User::find(3));

        // The host "fixes" the fee after people have already paid 40 each.
        $giveaway->settings = json_encode(['entry_cost_points' => 999]);
        $giveaway->save();

        $refunded = $this->cancelService()->cancel(Giveaway::find($giveaway->id));

        $this->assertSame(2, $refunded);
        $awards = MemoryPointsGateway::ledger('award');
        $this->assertCount(2, $awards);
        foreach ($awards as $row) {
            $this->assertSame(40, $row['amount'], 'must refund the recorded paid_amount, never the edited fee');
        }
        $this->assertSame(Giveaway::STATUS_CANCELLED, Giveaway::find($giveaway->id)->status);
    }

    public function test_cancelling_a_draft_or_free_giveaway_moves_no_money(): void
    {
        $this->addMembers(2);
        $this->bootWithGiveaways();

        $draft = $this->createGiveaway(['status' => Giveaway::STATUS_DRAFT]);
        $free = $this->createGiveaway();
        $entries = $this->app()->getContainer()->make(EntryService::class);
        $entries->enter($free, User::find(2));

        $this->assertSame(0, $this->cancelService()->cancel($draft));
        $this->assertSame(0, $this->cancelService()->cancel(Giveaway::find($free->id)));
        $this->assertSame([], MemoryPointsGateway::ledger('award'));
        $this->assertSame(0, GiveawayRefund::pending()->count());
    }

    public function test_cancelling_a_drawn_giveaway_is_rejected_by_the_atomic_claim(): void
    {
        $this->addMembers(2);
        $this->bootWithGiveaways();

        $giveaway = $this->createGiveaway();
        $this->app()->getContainer()->make(\Ygpynet\Giveaways\DrawService::class)
            ->draw(Giveaway::find($giveaway->id));

        $stale = Giveaway::find($giveaway->id);
        $stale->status = Giveaway::STATUS_ACTIVE; // pretend a stale HTTP worker

        $this->expectException(\DomainException::class);
        $this->cancelService()->cancel($stale);
    }

    public function test_refund_failure_queues_and_retry_drains(): void
    {
        $this->addMembers(2);
        $this->bootWithGiveaways([new SwapFailingGateway()]);

        $giveaway = $this->createGiveaway([], ['entry_cost_points' => 40]);
        $entries = $this->app()->getContainer()->make(EntryService::class);
        $entries->enter($giveaway, User::find(2));

        // Points system goes down between the deduction and the refund...
        FailingAwardGateway::$fail = true;

        $refunded = $this->cancelService()->cancel(Giveaway::find($giveaway->id));
        $this->assertSame(0, $refunded, 'refund failed while the gateway was down');

        $queued = GiveawayRefund::pending()->get();
        $this->assertCount(1, $queued, 'the owed money must be recorded durably');
        $this->assertSame(40, (int) $queued[0]->amount);
        $this->assertNotEmpty($queued[0]->last_error);

        // ...and is drained once it comes back.
        FailingAwardGateway::$fail = false;

        $ok = $this->app()->getContainer()->make(RefundService::class)->retry($queued[0]->refresh());

        $this->assertTrue($ok);
        $this->assertNotNull($queued[0]->refresh()->refunded_at, 'the row stays as audit evidence once refunded');
        $this->assertSame(0, GiveawayRefund::pending()->count());
    }

    /**
     * Bug isolation (regression): deleting a LIVE paid giveaway used to wipe
     * the entry rows (with their paid_amount evidence) without ever refunding
     * — entrant money vanished silently. Delete of a live giveaway must take
     * it down as cancelled and refund every recorded payment first.
     */
    public function test_deleting_a_running_paid_giveaway_refunds_entrants(): void
    {
        $this->addMembers(2);
        $this->bootWithGiveaways();

        $giveaway = $this->createGiveaway([], ['entry_cost_points' => 10]);
        $entries = $this->app()->getContainer()->make(EntryService::class);
        $entries->enter($giveaway, User::find(2));

        $balanceBeforeDelete = MemoryPointsGateway::balance(2);
        $this->assertSame(90, $balanceBeforeDelete, 'precondition: the 10-point fee was charged');

        $response = $this->send($this->request('DELETE', "/api/giveaways/{$giveaway->id}", ['authenticatedAs' => 1]));

        $this->assertSame(204, $response->getStatusCode());
        $this->assertNull(Giveaway::find($giveaway->id));
        $this->assertSame(
            $balanceBeforeDelete + 10,
            MemoryPointsGateway::balance(2),
            'deleting a live paid giveaway must not strand entrant points'
        );
        $this->assertSame(0, GiveawayRefund::pending()->count());
    }
}
