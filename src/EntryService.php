<?php

namespace Ygpynet\Giveaways;

use Carbon\Carbon;
use Ygpynet\Giveaways\Contract\EligibilityRule;
use Ygpynet\Giveaways\Contract\PointsGateway;
use Ygpynet\Giveaways\Event\GiveawayWasEntered;
use Ygpynet\Giveaways\Exception\GiveawayClosedException;
use Flarum\Locale\TranslatorInterface;
use Flarum\User\User;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;

/** Creates base entries and awards bonus entries, enforcing eligibility. */
class EntryService
{
    /**
     * @param EligibilityRule[] $eligibilityRules rule-based gates (tagged
     *        services); the structural gates (login, running window) stay
     *        in ineligibleReason() itself.
     */
    public function __construct(
        protected TranslatorInterface $translator,
        protected ConnectionInterface $db,
        protected PointsGateway $points,
        protected Dispatcher $events,
        protected RefundService $refunds,
        protected array $eligibilityRules = []
    ) {
    }

    /** Points charged to enter this giveaway (0 = free). */
    public function entryCost(Giveaway $giveaway): int
    {
        return (int) ($giveaway->settingsArray()['entry_cost_points'] ?? 0);
    }

    /**
     * Returns a human (localized) reason the user can't enter, or null if
     * eligible. Structural gates (login, running window) are checked here;
     * every rule-based gate (min posts, min age, points balance, plus any
     * third-party EligibilityRule) is delegated to the injected rule set in
     * registration order — the first non-null reason wins.
     */
    public function ineligibleReason(Giveaway $giveaway, User $user): ?string
    {
        if ($user->isGuest()) {
            return $this->translator->trans('ygpynet-giveaways.api.enter_login');
        }
        if (! $giveaway->isRunning()) {
            return $this->closedReason($giveaway);
        }
        foreach ($this->eligibilityRules as $rule) {
            if (($reason = $rule->check($giveaway, $user)) !== null) {
                return $reason;
            }
        }
        return null;
    }

    /**
     * Why a non-running giveaway is closed. A giveaway whose status is still
     * 'active' can be closed because its window simply hasn't opened yet, or
     * because it has already ended — those read very differently to a user,
     * so they get their own messages.
     */
    protected function closedReason(Giveaway $giveaway): string
    {
        if ($giveaway->status === 'drawn') {
            return $this->translator->trans('ygpynet-giveaways.api.enter_drawn');
        }
        if ($giveaway->status === 'cancelled') {
            return $this->translator->trans('ygpynet-giveaways.api.enter_cancelled');
        }
        if ($giveaway->starts_at && $giveaway->starts_at->gt(Carbon::now())) {
            return $this->translator->trans('ygpynet-giveaways.api.enter_closed');
        }
        return $this->translator->trans('ygpynet-giveaways.api.enter_ended');
    }

    /**
     * Idempotent base entry. Caller should check ineligibleReason() first.
     *
     * The insert happens inside a transaction that holds a row lock on the
     * giveaway and re-validates the running window under the lock. This closes
     * the race where the controller's eligibility check passes, a concurrent
     * draw() claims active → drawn, and we then write an entry into a drawn
     * giveaway — a "ghost entry" that would never appear in the published
     * entrant hash and so break the provably-fair guarantee.
     *
     * When the giveaway costs points, the charge is taken via the
     * ramon/point-system repository BEFORE the locked section (the point
     * system may live on its own connection and cannot join our transaction);
     * if anything then fails, the charge is refunded so the user never loses
     * points without getting an entry.
     */
    public function enter(Giveaway $giveaway, User $user): GiveawayEntry
    {
        $entry = GiveawayEntry::query()
            ->where('giveaway_id', $giveaway->id)->where('user_id', $user->id)->first();
        if ($entry) {
            return $entry;
        }

        $cost = $this->entryCost($giveaway);
        if ($cost > 0) {
            if (! $this->points->available()) {
                throw new \RuntimeException('This giveaway costs points but no points integration is bound.');
            }
            // Throws \DomainException when the balance is insufficient — the
            // controller maps that to a localized validation error.
            $this->points->deduct($user, $cost, PointsGateway::REASON_ENTRY, PointsGateway::REFERENCE_TYPE, (int) $giveaway->id);
        }

        try {
            $entry = $this->db->transaction(function () use ($giveaway, $user, $cost) {
                // lockForUpdate serializes us against draw()'s atomic claim:
                // whichever wins, the loser sees committed state. SQLite ignores
                // the lock hint harmlessly (single-writer anyway).
                $fresh = Giveaway::query()->whereKey($giveaway->id)->lockForUpdate()->first();

                if (! $fresh || ! $fresh->isRunning()) {
                    throw new GiveawayClosedException(
                        $fresh ? $this->closedReason($fresh) : 'ygpynet-giveaways.api.enter_ended'
                    );
                }

                return $this->createEntry($fresh, $user, $cost);
            });
        } catch (\Throwable $e) {
            $this->refundEntryFee($user, $cost, $giveaway);
            if ($e instanceof QueryException) {
                // A concurrent identical insert beat us to it and hit the unique
                // (giveaway_id, user_id) constraint — fetch the existing row
                // instead of surfacing a 500 to the user.
                return GiveawayEntry::query()
                    ->where('giveaway_id', $giveaway->id)->where('user_id', $user->id)->firstOrFail();
            }
            throw $e;
        }

        $this->events->dispatch(new GiveawayWasEntered($giveaway, $user, $entry));

        return $entry;
    }

    /** Record the entry, persisting the exact fee charged (fund audit). */
    protected function createEntry(Giveaway $giveaway, User $user, int $paidAmount = 0): GiveawayEntry
    {
        $entry = new GiveawayEntry();
        $entry->giveaway_id = $giveaway->id;
        $entry->user_id = $user->id;
        $entry->entries = 1;
        $entry->paid_amount = $paidAmount;
        $entry->sources = json_encode(['base' => 1]);
        $entry->created_at = Carbon::now();
        $entry->updated_at = Carbon::now();

        $entry->save();

        return $entry;
    }

    /**
     * Give back an entry fee whose entry did not materialize. Must never
     * throw: a failed immediate refund is queued in giveaway_refunds and
     * drained by giveaways:retry-refunds, so the original entry error (if
     * any) still propagates to the caller with the money safely recorded.
     */
    protected function refundEntryFee(User $user, int $cost, Giveaway $giveaway): void
    {
        $this->refunds->refund($giveaway, $user, $cost);
    }

    /**
     * Award $n bonus entries under a named source, once per source, only to users
     * who have already entered a running giveaway. No-op otherwise.
     *
     * Two locks, in the same order as enter():
     *   1. the GIVEAWAY row (lockForUpdate) — re-read under lock and re-checked
     *      with a FRESH isRunning(). Without this, an award racing a draw could
     *      mutate an entrant's weight AFTER the entrant_hash was published,
     *      silently breaking the provably-fair guarantee (and making an honest
     *      draw fail giveaways:verify). The passed-in model may be as stale as
     *      the request that loaded it — AwardPostBonus hands us exactly that.
     *   2. the ENTRY row — the sources JSON read-modify-write must not lose a
     *      concurrent award for a different source to a clobbered sum.
     */
    public function addBonus(Giveaway $giveaway, User $user, string $source, int $n): void
    {
        if ($n <= 0) {
            return;
        }
        $this->db->transaction(function () use ($giveaway, $user, $source, $n) {
            $fresh = Giveaway::query()->whereKey($giveaway->id)->lockForUpdate()->first();
            if (! $fresh || ! $fresh->isRunning()) {
                return; // drawn/cancelled/closed between load and lock
            }

            $entry = GiveawayEntry::query()
                ->where('giveaway_id', $fresh->id)->where('user_id', $user->id)
                ->lockForUpdate()->first();
            if (! $entry) {
                return; // must have entered first
            }
            $sources = $entry->sourcesArray();
            if (isset($sources[$source])) {
                return; // already awarded this source
            }
            $sources[$source] = $n;
            $entry->sources = json_encode($sources);
            $entry->entries = array_sum($sources);
            $entry->updated_at = Carbon::now();
            $entry->save();
        });
    }
}
