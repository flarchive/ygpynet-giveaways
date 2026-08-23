<?php

namespace ErnestDefoe\Giveaways\Support;

use ErnestDefoe\Giveaways\Contract\WinnerPicker;

/**
 * The published, provably-fair algorithm: per winner slot i, reduce
 * SHA-256(seed:i) over the weighted entry pool and remove the winner before
 * the next pick. Deterministic in (pool, seed) → independently verifiable.
 */
class HashWeightedPicker implements WinnerPicker
{
    public function pick(array $pool, string $seed, int $count): array
    {
        // Defensive normalization: a non-positive entry count must never produce
        // a degenerate pool, and this keeps pick() well-defined on any input.
        $pool = array_map(
            fn ($row) => ['user_id' => (int) $row['user_id'], 'entries' => max(1, (int) $row['entries'])],
            $pool
        );

        $winners = [];
        $slots = min($count, count($pool));

        for ($i = 0; $i < $slots; $i++) {
            $total = array_sum(array_column($pool, 'entries'));
            if ($total <= 0) {
                break;
            }
            // 60 bits of the per-slot hash → fits a 64-bit int → uniform-ish mod total.
            $r = hexdec(substr(hash('sha256', $seed . ':' . $i), 0, 15)) % $total;

            $acc = 0;
            $pickIdx = count($pool) - 1;
            foreach ($pool as $idx => $row) {
                $acc += $row['entries'];
                if ($r < $acc) {
                    $pickIdx = $idx;
                    break;
                }
            }
            $winners[] = $pool[$pickIdx]['user_id'];
            array_splice($pool, $pickIdx, 1);
        }

        return $winners;
    }
}
