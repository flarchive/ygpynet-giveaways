<?php

namespace Ygpynet\Giveaways;

use Ygpynet\Giveaways\Event\GiveawayWasCancelled;
use Flarum\User\User;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Cancels a giveaway (draft or active → cancelled, atomically claimed) and
 * refunds every entrant's paid entry fee.
 *
 * Fund audit: each entrant is refunded the amount RECORDED on their entry
 * (giveaway_entries.paid_amount), i.e. what they actually paid — no longer
 * the possibly-edited current entry_cost_points setting. A failed refund is
 * never fatal and never lost: RefundService queues it into giveaway_refunds
 * for giveaways:retry-refunds to drain.
 */
class CancelService
{
    public function __construct(
        protected RefundService $refunds,
        protected Dispatcher $events
    ) {
    }

    /**
     * @return int number of entrants whose fee was refunded (0 for free giveaways)
     */
    public function cancel(Giveaway $giveaway): int
    {
        // Re-read inside the claim: the in-memory model may be stale relative
        // to a concurrent draw() — the atomic WHERE clause decides.
        $claimed = Giveaway::query()
            ->whereKey($giveaway->id)
            ->whereIn('status', [Giveaway::STATUS_DRAFT, Giveaway::STATUS_ACTIVE])
            ->update(['status' => Giveaway::STATUS_CANCELLED]);

        if (! $claimed) {
            // A concurrent draw got there first (or it was already terminal).
            throw new \DomainException('giveaway_not_cancellable');
        }

        $refunded = 0;
        foreach ($this->paidEntries($giveaway->id) as $entry) {
            // Entry rows cascade-delete with their user, so a missing account
            // here is a pathological race — skip it, the money trail stays in
            // the points system's own transaction log.
            $user = User::find((int) $entry->user_id);
            if (! $user) {
                continue;
            }
            // The points system may live on another connection and cannot
            // join our transaction — like notifications, refunds run after
            // the status change is durable. refund() never throws.
            if ($this->refunds->refund($giveaway, $user, (int) $entry->paid_amount)) {
                $refunded++;
            }
        }

        $this->events->dispatch(new GiveawayWasCancelled($giveaway));

        return $refunded;
    }

    /**
     * Entrants that actually paid a fee (user_id + paid_amount pairs).
     *
     * @return \Illuminate\Support\Collection<int, object{user_id:int, paid_amount:int}>
     */
    protected function paidEntries(int $giveawayId): \Illuminate\Support\Collection
    {
        return GiveawayEntry::query()
            ->where('giveaway_id', $giveawayId)
            ->where('paid_amount', '>', 0)
            ->orderBy('id')
            ->get(['user_id', 'paid_amount']);
    }
}
