<?php

namespace ErnestDefoe\Giveaways;

use Carbon\Carbon;
use ErnestDefoe\Giveaways\Support\PointSystem;
use Flarum\Locale\TranslatorInterface;
use Flarum\User\User;
use Illuminate\Database\QueryException;
use Ramon\PointSystem\Repository\PointsRepository;

/** Creates base entries and awards bonus entries, enforcing eligibility. */
class EntryService
{
    public function __construct(protected TranslatorInterface $translator)
    {
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
            if (! PointSystem::available()) {
                return $this->translator->trans('ernestdefoe-giveaways.api.enter_points_unavailable');
            }
            $balance = PointSystem::balanceOf($user);
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

    /** Idempotent base entry. Caller should check ineligibleReason() first.
     *
     * When the giveaway costs points, the charge is taken atomically via the
     * ramon/point-system repository BEFORE the entry row is written; if the
     * insert then fails (most commonly a concurrent identical insert hitting
     * the unique constraint), the charge is refunded so the user never loses
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
        $points = null;
        if ($cost > 0) {
            $points = PointSystem::repository();
            if (! $points) {
                throw new \RuntimeException('This giveaway costs points but ramon/point-system is not available.');
            }
            // Throws \DomainException when the balance is insufficient — the
            // controller maps that to a localized validation error.
            $points->deduct($user, $cost, PointSystem::REASON_ENTRY, PointSystem::REFERENCE_TYPE, (int) $giveaway->id);
        }

        try {
            return $this->createEntry($giveaway, $user);
        } catch (\Throwable $e) {
            $this->refundEntryFee($points, $user, $cost, $giveaway);
            if ($e instanceof QueryException) {
                // A concurrent identical insert beat us to it and hit the unique
                // (giveaway_id, user_id) constraint — fetch the existing row
                // instead of surfacing a 500 to the user.
                return GiveawayEntry::query()
                    ->where('giveaway_id', $giveaway->id)->where('user_id', $user->id)->firstOrFail();
            }
            throw $e;
        }
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
    protected function refundEntryFee(?PointsRepository $points, User $user, int $cost, Giveaway $giveaway): void
    {
        if ($points && $cost > 0) {
            $points->award($user, $cost, PointSystem::REASON_REFUND, PointSystem::REFERENCE_TYPE, (int) $giveaway->id);
        }
    }

    /**
     * Award $n bonus entries under a named source, once per source, only to users
     * who have already entered a running giveaway. No-op otherwise.
     */
    public function addBonus(Giveaway $giveaway, User $user, string $source, int $n): void
    {
        if ($n <= 0 || ! $giveaway->isRunning()) {
            return;
        }
        $entry = GiveawayEntry::query()
            ->where('giveaway_id', $giveaway->id)->where('user_id', $user->id)->first();
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
    }
}
