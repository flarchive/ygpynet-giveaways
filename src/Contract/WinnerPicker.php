<?php

namespace ErnestDefoe\Giveaways\Contract;

/**
 * Strategy for selecting winners from an entrant pool.
 *
 * Implementations must be deterministic in (pool, seed) — the same inputs
 * must always yield the same winners — because the result is published and
 * third parties verify it. Swap the binding in a service provider to change
 * draw mechanics without touching DrawService.
 */
interface WinnerPicker
{
    /**
     * @param array<int, array{user_id:int, entries:int}> $pool entrant weights;
     *   implementations must defensively normalize non-positive entries
     * @param string $seed published random seed captured at draw time
     * @param int $count number of winner slots
     * @return int[] winner user ids in draw order (distinct, ⊆ pool)
     */
    public function pick(array $pool, string $seed, int $count): array;
}
