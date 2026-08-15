<?php

namespace ErnestDefoe\Giveaways\Tests;

use ErnestDefoe\Giveaways\DrawService;
use PHPUnit\Framework\TestCase;

/**
 * The draw algorithm is the product's fairness core: `pick()` must be
 * deterministic in (pool, seed) so anyone can re-run it and verify winners.
 */
class DrawServiceTest extends TestCase
{
    private function service(): DrawService
    {
        // pick() is a pure function and never touches the injected collaborators;
        // instantiate without the constructor so the test needs no Flarum container.
        return (new \ReflectionClass(DrawService::class))->newInstanceWithoutConstructor();
    }

    public function testEmptyPoolYieldsNoWinners(): void
    {
        $this->assertSame([], $this->service()->pick([], 'seed', 3));
    }

    public function testCountBeyondPoolReturnsEveryEntrant(): void
    {
        $pool = [
            ['user_id' => 1, 'entries' => 2],
            ['user_id' => 2, 'entries' => 3],
            ['user_id' => 3, 'entries' => 1],
        ];

        $winners = $this->service()->pick($pool, 'abc', 10);

        sort($winners);
        $this->assertSame([1, 2, 3], $winners);
    }

    public function testSingleEntrantAlwaysWins(): void
    {
        $pool = [['user_id' => 42, 'entries' => 5]];

        $this->assertSame([42], $this->service()->pick($pool, 'anything', 1));
    }

    public function testSamePoolAndSeedAlwaysProduceSameWinners(): void
    {
        $pool = [];
        for ($i = 1; $i <= 50; $i++) {
            $pool[] = ['user_id' => $i, 'entries' => ($i % 5) + 1];
        }

        $service = $this->service();
        $first = $service->pick($pool, 'fixed-seed', 10);
        $second = $service->pick($pool, 'fixed-seed', 10);

        $this->assertSame($first, $second);
    }

    public function testWinnersAreDistinctAndFromThePool(): void
    {
        $pool = [];
        for ($i = 1; $i <= 20; $i++) {
            $pool[] = ['user_id' => $i, 'entries' => 2];
        }

        $winners = $this->service()->pick($pool, 'seed-2', 5);

        $this->assertCount(5, $winners);
        $this->assertCount(5, array_unique($winners), 'winners must be distinct');
        foreach ($winners as $uid) {
            $this->assertContains($uid, array_column($pool, 'user_id'), "winner $uid must be in the pool");
        }
    }

    public function testWinnersAreRemovedBeforeNextSlot(): void
    {
        // Two slots: the first winner must not appear again in the second slot.
        $pool = [['user_id' => 1, 'entries' => 1], ['user_id' => 2, 'entries' => 1]];

        $winners = $this->service()->pick($pool, 'seed-3', 2);

        $this->assertCount(2, array_unique($winners));
        $this->assertContains(1, $winners);
        $this->assertContains(2, $winners);
    }

    public function testZeroOrNegativeEntriesAreTreatedAsOne(): void
    {
        $pool = [
            ['user_id' => 1, 'entries' => 0],
            ['user_id' => 2, 'entries' => -3],
        ];

        $this->assertCount(2, $this->service()->pick($pool, 'seed-4', 2));
    }

    public function testDifferentSeedsStillSelectValidWinners(): void
    {
        $pool = [];
        for ($i = 1; $i <= 100; $i++) {
            $pool[] = ['user_id' => $i, 'entries' => ($i * 7) % 10 + 1];
        }

        $ids = array_column($pool, 'user_id');
        foreach (['seed-a', 'seed-b', 'seed-c', 'seed-d'] as $seed) {
            foreach ($this->service()->pick($pool, $seed, 3) as $uid) {
                $this->assertContains($uid, $ids);
            }
        }
    }
}
