<?php

namespace ErnestDefoe\Giveaways;

use Carbon\Carbon;
use ErnestDefoe\Giveaways\Contract\WinnerPicker;
use ErnestDefoe\Giveaways\Event\GiveawayWasDrawn;
use ErnestDefoe\Giveaways\Notification\GiveawayWonBlueprint;
use Flarum\Notification\NotificationSyncer;
use Flarum\User\User;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;

/**
 * Provably-fair winner selection. At draw time we publish:
 *   - draw_seed     (random, generated now)
 *   - entrant_hash  (sha256 of the canonical "user_id:entries" list, sorted)
 * Anyone holding the entrant list can re-run the WinnerPicker with the seed
 * and verify the winners — the draw can't be rigged after the fact.
 */
class DrawService
{
    public function __construct(
        protected NotificationSyncer $notifications,
        protected ConnectionInterface $db,
        protected WinnerPicker $picker,
        protected Dispatcher $events
    ) {
    }

    public function draw(Giveaway $giveaway): void
    {
        if ($giveaway->status !== 'active') {
            return;
        }

        $winnerIds = [];

        // Everything that changes the giveaway's state happens in one
        // transaction. The active → drawn transition is claimed atomically, so
        // a manual "draw now" racing the scheduler can never double-draw.
        $this->db->transaction(function () use ($giveaway, &$winnerIds) {
            $claimed = Giveaway::query()
                ->where('id', $giveaway->id)
                ->where('status', 'active')
                ->update(['status' => 'drawn']);

            if (! $claimed) {
                return; // a concurrent draw already claimed it
            }

            $giveaway->status = 'drawn';

            $entries = $giveaway->entries()->orderBy('user_id')->get(['user_id', 'entries']);

            $canonical = $entries->map(fn ($e) => $e->user_id . ':' . $e->entries)->implode(',');
            $hash = hash('sha256', $canonical);
            $seed = bin2hex(random_bytes(16));

            $pool = $entries->map(fn ($e) => ['user_id' => (int) $e->user_id, 'entries' => max(1, (int) $e->entries)])->values()->all();
            $winnerIds = $this->picker->pick($pool, $seed, (int) $giveaway->winner_count);

            foreach ($winnerIds as $pos => $uid) {
                $w = new GiveawayWinner();
                $w->giveaway_id = $giveaway->id;
                $w->user_id = $uid;
                $w->position = $pos + 1;
                $w->created_at = Carbon::now();
                $w->save();
            }

            $giveaway->draw_seed = $seed;
            $giveaway->entrant_hash = $hash;
            $giveaway->drawn_at = Carbon::now();
            $giveaway->save();
        });

        // Notifications run after the transaction commits, so a failed alert
        // can never roll a completed draw back.
        $this->events->dispatch(new GiveawayWasDrawn($giveaway, $winnerIds));
        $this->notifyWinners($giveaway, $winnerIds);
    }

    /** Send each winner a "you won" alert. Failures here never block the draw. */
    protected function notifyWinners(Giveaway $giveaway, array $winnerIds): void
    {
        foreach ($winnerIds as $pos => $uid) {
            try {
                $user = User::find($uid);
                if ($user) {
                    $this->notifications->sync(
                        new GiveawayWonBlueprint($giveaway, $pos + 1),
                        [$user]
                    );
                }
            } catch (\Throwable $e) {
                // Best-effort: a notification failure must not undo a completed draw.
            }
        }
    }

    /**
     * Deterministic weighted pick of N distinct winners from a [user_id,entries]
     * pool, seeded by $seed. Pure function of (pool, seed) → verifiable.
     *
     * The algorithm lives in the injected {@see WinnerPicker} (default:
     * Support\HashWeightedPicker); this method remains as a thin BC delegate.
     *
     * @param array<int, array{user_id:int, entries:int}> $pool
     * @return int[] winner user ids in draw order
     */
    public function pick(array $pool, string $seed, int $count): array
    {
        return $this->picker->pick($pool, $seed, $count);
    }
}
