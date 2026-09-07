<?php

namespace ErnestDefoe\Giveaways;

use Carbon\Carbon;
use ErnestDefoe\Giveaways\Contract\PointsGateway;
use ErnestDefoe\Giveaways\Event\GiveawayWasEntered;
use ErnestDefoe\Giveaways\Exception\GiveawayClosedException;
use Flarum\Locale\TranslatorInterface;
use Flarum\User\User;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;

/** Creates base entries and awards bonus entries, enforcing eligibility. */
class EntryService
{
    public function __construct(
        protected TranslatorInterface $translator,
        protected ConnectionInterface $db,
        protected PointsGateway $points,
        protected Dispatcher $events
    ) {
    }

    /** Points charged to enter this giveaway (0 = free). */
    public function entryCost(Giveaway $giveaway): int
    {
        return (int) ($giveaway->settingsArray()['entry_cost_points'] ?? 0);
    }

    /** Returns a human (localized) reason the user can't enter, or null if eligible. */
    public function ineligibleReason(Giveaway $giveaway, User $user): ?string
    {
        if ($user->isGuest()) {
            return $this->translator->trans('ernestdefoe-giveaways.api.enter_login');
        }
        if (! $giveaway->isRunning()) {
            return $this->closedReason($giveaway);
        }
        $s = $giveaway->settingsArray();
        if (($s['min_posts'] ?? 0) > 0 && (int) $user->comment_count < (int) $s['min_posts']) {
            return $this->translator->trans('ernestdefoe-giveaways.api.enter_min_posts', ['count' => (int) $s['min_posts']]);
        }
        if (($s['min_age_days'] ?? 0) > 0 && $user->joined_at && $user->joined_at->gt(Carbon::now()->subDays((int) $s['min_age_days']))) {
            return $this->translator->trans('ernestdefoe-giveaways.api.enter_too_new');
        }
        $cost = $this->entryCost($giveaway);
        if ($cost > 0) {
            if (! $this->points->available()) {
                return $this->translator->trans('ernestdefoe-giveaways.api.enter_points_unavailable');
            }
            $balance = $this->points->balanceOf($user);
            if ($balance < $cost) {
                return $this->translator->trans('ernestdefoe-giveaways.api.enter_insufficient_points', ['cost' => $cost, 'balance' => $balance]);
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
            return $this->translator->trans('ernestdefoe-giveaways.api.enter_drawn');
        }
        if ($giveaway->status === 'cancelled') {
            return $this->translator->trans('ernestdefoe-giveaways.api.enter_cancelled');
        }
        if ($giveaway->starts_at && $giveaway->starts_at->gt(Carbon::now())) {
            return $this->translator->trans('ernestdefoe-giveaways.api.enter_closed');
        }
        return $this->translator->trans('ernestdefoe-giveaways.api.enter_ended');
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
            $entry = $this->db->transaction(function () use ($giveaway, $user) {
                // lockForUpdate serializes us against draw()'s atomic claim:
                // whichever wins, the loser sees committed state. SQLite ignores
                // the lock hint harmlessly (single-writer anyway).
                $fresh = Giveaway::query()->whereKey($giveaway->id)->lockForUpdate()->first();

                if (! $fresh || ! $fresh->isRunning()) {
                    throw new GiveawayClosedException(
                        $fresh ? $this->closedReason($fresh) : 'ernestdefoe-giveaways.api.enter_ended'
                    );
                }

                return $this->createEntry($fresh, $user);
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

    protected function createEntry(Giveaway $giveaway, User $user): GiveawayEntry
    {
        $entry = new GiveawayEntry();
        $entry->giveaway_id = $giveaway->id;
        $entry->user_id = $user->id;
        $entry->entries = 1;
        $entry->sources = json_encode(['base' => 1]);
        $entry->created_at = Carbon::now();
        $entry->updated_at = Carbon::now();

        $entry->save();

        return $entry;
    }

    /** Give back an entry fee whose entry did not materialize. */
    protected function refundEntryFee(User $user, int $cost, Giveaway $giveaway): void
    {
        if ($cost > 0 && $this->points->available()) {
            $this->points->award($user, $cost, PointsGateway::REASON_REFUND, PointsGateway::REFERENCE_TYPE, (int) $giveaway->id);
        }
    }

    /**
     * Award $n bonus entries under a named source, once per source, only to users
     * who have already entered a running giveaway. No-op otherwise.
     *
     * The read-modify-write of the sources JSON runs inside a transaction with
     * a row lock on the entry. Without it, two concurrent awards for DIFFERENT
     * sources (e.g. the core 'post' bonus and a third-party one) could both
     * read the same JSON, and the second save would silently erase the first
     * award while recomputing the entries sum.
     */
    public function addBonus(Giveaway $giveaway, User $user, string $source, int $n): void
    {
        if ($n <= 0 || ! $giveaway->isRunning()) {
            return;
        }
        $this->db->transaction(function () use ($giveaway, $user, $source, $n) {
            $entry = GiveawayEntry::query()
                ->where('giveaway_id', $giveaway->id)->where('user_id', $user->id)
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
