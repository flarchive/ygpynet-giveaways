<?php

namespace Ygpynet\Giveaways\Support;

use Ygpynet\Giveaways\Contract\WinnerPicker;
use Ygpynet\Giveaways\Giveaway;

/**
 * Recomputes a completed draw from the published entrant list + seed so the
 * provably-fair guarantee can be checked independently — server-side by
 * giveaways:verify, and conceptually by any third party holding the same data.
 *
 * The canonicalization MUST match DrawService exactly, or verification would
 * reject honest draws. Both go through this class to keep one source of truth.
 */
class DrawVerifier
{
    public function __construct(protected WinnerPicker $picker)
    {
    }

    /**
     * Accepts GiveawayEntry models or projection objects — anything exposing
     * user_id/entries, exactly like DrawService's `get(['user_id','entries'])`.
     *
     * @param iterable<object> $entries will be sorted by user_id
     * @return array{0:string,1:string} [canonical entrant string, sha256 hash]
     */
    public static function fingerprint(iterable $entries): array
    {
        $rows = [];
        foreach ($entries as $e) {
            $rows[(int) $e->user_id] = (int) $e->entries;
        }
        ksort($rows);

        $canonical = implode(',', array_map(
            fn ($uid, $n) => $uid . ':' . $n,
            array_keys($rows),
            array_values($rows)
        ));

        return [$canonical, hash('sha256', $canonical)];
    }

    /** @return array<int, array{user_id:int, entries:int}> */
    public static function pool(iterable $entries): array    {
        $pool = [];
        foreach ($entries as $e) {
            $pool[] = ['user_id' => (int) $e->user_id, 'entries' => max(1, (int) $e->entries)];
        }

        return $pool;
    }

    /**
     * Recompute the winner list for a drawn giveaway from its published seed.
     *
     * @return int[] winner user ids in draw order
     */
    public function recomputeWinners(Giveaway $giveaway): array
    {
        $entries = $giveaway->entries()->get();
        $seed = (string) $giveaway->draw_seed;

        return $this->picker->pick(self::pool($entries), $seed, (int) $giveaway->winner_count);
    }

    /**
     * Full integrity report for a drawn giveaway: does the recomputed hash
     * match the published one, and do the recomputed winners match the stored
     * winners and the seed?
     *
     * @return array{
     *   ok: bool,
     *   hash_ok: bool,
     *   winners_ok: bool,
     *   published_hash: ?string,
     *   recomputed_hash: string,
     *   published_winners: array<int,array{position:int,user_id:int}>,
     *   recomputed_winners: int[],
     *   problems: string[]
     * }
     */
    public function verify(Giveaway $giveaway): array
    {
        $entries = $giveaway->entries()->get();
        [$canonical, $hash] = self::fingerprint($entries);

        $publishedWinners = $giveaway->winners()->orderBy('position')->get()
            ->map(fn ($w) => ['position' => (int) $w->position, 'user_id' => (int) $w->user_id])
            ->all();

        $recomputed = $this->picker->pick(
            self::pool($entries),
            (string) $giveaway->draw_seed,
            (int) $giveaway->winner_count
        );

        $hashOk = $hash === $giveaway->entrant_hash;

        $recordedIds = array_map(fn ($w) => $w['user_id'], $publishedWinners);
        $winnersOk = $recordedIds === $recomputed;

        $problems = [];
        if (! $hashOk) {
            $problems[] = 'entrant list changed since the draw (hash mismatch)';
        }
        if (! $winnersOk) {
            $problems[] = 'stored winners do not match the seed';
        }

        return [
            'ok'                 => $hashOk && $winnersOk,
            'hash_ok'            => $hashOk,
            'winners_ok'         => $winnersOk,
            'canonical'          => $canonical,
            'published_hash'     => $giveaway->entrant_hash,
            'recomputed_hash'    => $hash,
            'published_winners'  => $publishedWinners,
            'recomputed_winners' => $recomputed,
            'problems'           => $problems,
        ];
    }
}
