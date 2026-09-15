<?php

namespace Ygpynet\Giveaways\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Ygpynet\Giveaways\Support\HashWeightedPicker;

class HashWeightedPickerTest extends TestCase
{
    protected HashWeightedPicker $picker;

    protected function setUp(): void
    {
        $this->picker = new HashWeightedPicker();
    }

    protected function pool(): array
    {
        return [
            ['user_id' => 3, 'entries' => 5],
            ['user_id' => 7, 'entries' => 1],
            ['user_id' => 11, 'entries' => 42],
            ['user_id' => 23, 'entries' => 2],
        ];
    }

    public function test_same_pool_and_seed_always_produce_the_same_winners(): void
    {
        $first = $this->picker->pick($this->pool(), 'deadbeefcafe0123456789', 2);

        for ($i = 0; $i < 10; $i++) {
            $this->assertSame($first, $this->picker->pick($this->pool(), 'deadbeefcafe0123456789', 2));
        }
    }

    public function test_golden_regression_sample(): void
    {
        // Frozen output of the published algorithm. If this test breaks, the
        // draw algorithm changed — which invalidates verification of every
        // draw recorded before the change. That must only happen on purpose.
        $this->assertSame(
            [11, 3],
            $this->picker->pick($this->pool(), 'deadbeefcafe0123456789', 2)
        );
    }

    public function test_winners_are_distinct(): void
    {
        $winners = $this->picker->pick($this->pool(), 'seed-123', 4);

        $this->assertCount(4, $winners);
        $this->assertSame($winners, array_unique($winners));
    }

    public function test_count_is_capped_at_pool_size(): void
    {
        $winners = $this->picker->pick($this->pool(), 'seed-123', 10);

        $this->assertCount(4, $winners);
    }

    public function test_empty_pool_and_zero_count_yield_no_winners(): void
    {
        $this->assertSame([], $this->picker->pick([], 'seed-123', 3));
        $this->assertSame([], $this->picker->pick($this->pool(), 'seed-123', 0));
        $this->assertSame([], $this->picker->pick($this->pool(), 'seed-123', -1));
    }

    public function test_non_positive_entry_counts_never_break_the_draw(): void
    {
        $pool = [
            ['user_id' => 1, 'entries' => 0],
            ['user_id' => 2, 'entries' => -5],
        ];

        $winners = $this->picker->pick($pool, 'seed-123', 5);

        // Both survive as (at least) 1-entry tickets; order is seed-driven.
        $this->assertEqualsCanonicalizing([1, 2], $winners);
    }

    public function test_heavier_pools_win_more_often_over_independent_seeds(): void
    {
        // Statistical sanity check, not a fairness proof: with 999 : 1 weights
        // the heavy user must win the vast majority of single-slot draws.
        $pool = [
            ['user_id' => 1, 'entries' => 999],
            ['user_id' => 2, 'entries' => 1],
        ];

        $heavyWins = 0;
        for ($i = 0; $i < 200; $i++) {
            $winners = $this->picker->pick($pool, 'seed-' . $i, 1);
            if ($winners[0] === 1) {
                $heavyWins++;
            }
        }

        $this->assertGreaterThan(150, $heavyWins, 'weighted pick is not biased toward the heavier pool');
    }
}
