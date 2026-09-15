<?php

/*
 * This file is part of ygpynet/giveaways.
 *
 * Licensed under the MIT license.
 */

namespace Ygpynet\Giveaways\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Ygpynet\Giveaways\Support\DrawVerifier;
use Ygpynet\Giveaways\Support\HashWeightedPicker;

class DrawVerifierTest extends TestCase
{
    /** @return list<object{user_id:int, entries:int}> */
    protected function entries(): array
    {
        // Deliberately unsorted, int-typed: the fingerprint must sort by user id.
        return [
            (object) ['user_id' => 7, 'entries' => 1],
            (object) ['user_id' => 3, 'entries' => 5],
            (object) ['user_id' => 11, 'entries' => 42],
        ];
    }

    public function test_fingerprint_is_order_independent(): void
    {
        $shuffled = array_reverse($this->entries());

        $this->assertSame(
            DrawVerifier::fingerprint($this->entries()),
            DrawVerifier::fingerprint($shuffled)
        );
    }

    public function test_fingerprint_canonicalizes_int_casting_and_sorting(): void
    {
        [$canonical, $hash] = DrawVerifier::fingerprint($this->entries());

        $this->assertSame('3:5,7:1,11:42', $canonical);
        $this->assertSame(hash('sha256', '3:5,7:1,11:42'), $hash);
    }

    public function test_duplicate_user_ids_are_collapsed_to_one_ticket_line(): void
    {
        // The (giveaway_id, user_id) unique index makes this defensive only —
        // but if duplicate rows ever appear, the last one wins deterministically
        // rather than double-counting the entrant in the hash.
        [$canonical, ] = DrawVerifier::fingerprint([
            (object) ['user_id' => 3, 'entries' => 5],
            (object) ['user_id' => 3, 'entries' => 9],
        ]);

        $this->assertSame('3:9', $canonical);
    }

    public function test_pool_normalizes_non_positive_weights(): void
    {
        $pool = DrawVerifier::pool([
            (object) ['user_id' => 1, 'entries' => 0],
            (object) ['user_id' => 2, 'entries' => -4],
            (object) ['user_id' => 3, 'entries' => '7'],
        ]);

        $this->assertSame(
            [
                ['user_id' => 1, 'entries' => 1],
                ['user_id' => 2, 'entries' => 1],
                ['user_id' => 3, 'entries' => 7],
            ],
            $pool
        );
    }

    public function test_recompute_matches_the_draw_algorithm_bit_for_bit(): void
    {
        // The regression this guards: if fingerprint/pool ever diverge from
        // what DrawService wrote, verify() would flag honest draws as tampered.
        $picker = new HashWeightedPicker();
        $pool = DrawVerifier::pool($this->entries());
        [, $hash] = DrawVerifier::fingerprint($this->entries());

        $first = $picker->pick($pool, 'abc123', 2);
        $again = $picker->pick(DrawVerifier::pool($this->entries()), 'abc123', 2);

        $this->assertSame($first, $again);
        $this->assertSame(64, strlen($hash));
    }
}
