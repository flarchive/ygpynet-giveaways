<?php

namespace ErnestDefoe\Giveaways;

use ErnestDefoe\Giveaways\Contract\PointsGateway;
use ErnestDefoe\Giveaways\Event\GiveawayWasCancelled;
use Flarum\User\User;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use Psr\Log\LoggerInterface;

/**
 * Cancels a giveaway (draft or active → cancelled, atomically claimed) and
 * refunds every entrant's paid entry fee.
 *
 * Refund policy: each entrant is refunded the CURRENT entry_cost_points
 * setting. That is what every entrant was charged at entry time; if the host
 * changed the fee after entries came in this can drift from what an individual
 * actually paid — accepted trade-off, since entry rows record sources but not
 * the charged amount. Changing that would need a schema migration.
 */
class CancelService
{
    public function __construct(
        protected ConnectionInterface $db,
        protected PointsGateway $points,
        protected LoggerInterface $log,
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

        $cost = (int) ($giveaway->settingsArray()['entry_cost_points'] ?? 0);
        if ($cost <= 0 || ! $this->points->available()) {
            return 0;
        }

        $refunded = 0;
        foreach ($this->entrantIds($giveaway->id) as $userId) {
            try {
                // The points system may live on another connection and cannot
                // join our transaction — like notifications, refunds run after
                // the status change is durable. Failures are logged, never
                // thrown: a partial refund run must not "uncancel" anything.
                $this->points->award(
                    User::find($userId),
                    $cost,
                    PointsGateway::REASON_REFUND,
                    PointsGateway::REFERENCE_TYPE,
                    (int) $giveaway->id
                );
                $refunded++;
            } catch (\Throwable $e) {
                $this->log->warning('[giveaways] refund failed on cancel for user '
                    . $userId . ', giveaway #' . $giveaway->id . ': ' . $e->getMessage());
            }
        }

        $this->events->dispatch(new GiveawayWasCancelled($giveaway));

        return $refunded;
    }

    /** @return \Illuminate\Support\Collection<int, int> */
    protected function entrantIds(int $giveawayId): \Illuminate\Support\Collection
    {
        return GiveawayEntry::query()
            ->where('giveaway_id', $giveawayId)
            ->orderBy('id')
            ->pluck('user_id');
    }
}
