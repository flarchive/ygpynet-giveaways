<?php

namespace Ygpynet\Giveaways;

use Carbon\Carbon;
use Ygpynet\Giveaways\Contract\PointsGateway;
use Flarum\User\User;
use Psr\Log\LoggerInterface;

/**
 * The ONE path every point refund takes (failed entry, giveaway cancel, CLI
 * retry). Money movement must never throw at its callers and never vanish
 * into a log line: a failed award is persisted as a pending GiveawayRefund
 * and drained later by giveaways:retry-refunds.
 */
class RefundService
{
    public function __construct(
        protected PointsGateway $points,
        protected LoggerInterface $log
    ) {
    }

    /**
     * Refund $amount to $user for $giveaway. Never throws.
     *
     * @return bool whether the credit landed on the user's balance now
     */
    public function refund(Giveaway $giveaway, User $user, int $amount, string $reason = PointsGateway::REASON_REFUND): bool
    {
        if ($amount <= 0) {
            return true; // nothing owed
        }

        try {
            if (! $this->points->available()) {
                throw new \RuntimeException('points gateway unavailable');
            }
            $this->points->award($user, $amount, $reason, PointsGateway::REFERENCE_TYPE, (int) $giveaway->id);

            return true;
        } catch (\Throwable $e) {
            $this->log->warning('[giveaways] refund of ' . $amount . ' points failed for user '
                . $user->id . ', giveaway #' . $giveaway->id . ': ' . $e->getMessage());

            // refund() honors its never-throws contract even if queuing fails
            // (e.g. DB outage): the CRITICAL line then names the exact
            // user/giveaway/amount so ops can settle it by hand.
            try {
                GiveawayRefund::record((int) $giveaway->id, (int) $user->id, $amount, $reason, $e->getMessage());
            } catch (\Throwable $queueFailure) {
                $this->log->error('[giveaways] CRITICAL: refund of ' . $amount . ' points to user '
                    . $user->id . ' for giveaway #' . $giveaway->id . ' (' . $reason
                    . ') failed AND could not be queued: ' . $queueFailure->getMessage());
            }

            return false;
        }
    }

    /**
     * Retry one queued refund. Never throws; updates attempts/last_error so a
     * permanently failing row is still observable from the table.
     */
    public function retry(GiveawayRefund $refund): bool
    {
        $user = User::query()->find($refund->user_id);

        try {
            if (! $user) {
                throw new \RuntimeException('user account no longer exists');
            }
            if (! $this->points->available()) {
                throw new \RuntimeException('points gateway unavailable');
            }
            $this->points->award($user, (int) $refund->amount, (string) $refund->reason, PointsGateway::REFERENCE_TYPE, (int) $refund->giveaway_id);

            $refund->refunded_at = Carbon::now();
            $refund->last_error = null;
            $refund->save();

            return true;
        } catch (\Throwable $e) {
            $refund->attempts = (int) $refund->attempts + 1;
            $refund->last_error = mb_substr($e->getMessage(), 0, 255);

            try {
                $refund->save();
            } catch (\Throwable $saveFailure) {
                // Never abort the whole retry run over one un-savable row.
                $this->log->error('[giveaways] CRITICAL: refund #' . $refund->id
                    . ' failed AND its attempt could not be recorded: ' . $saveFailure->getMessage());
            }

            return false;
        }
    }
}
