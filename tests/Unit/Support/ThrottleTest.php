<?php

/*
 * This file is part of ygpynet/giveaways.
 *
 * Licensed under the MIT license.
 */

namespace Ygpynet\Giveaways\Tests\Unit\Support;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use PHPUnit\Framework\TestCase;
use Ygpynet\Giveaways\Support\Throttle;

class ThrottleTest extends TestCase
{
    protected Repository $store;
    protected Throttle $throttle;

    protected function setUp(): void
    {
        $this->store = new Repository(new ArrayStore(60));
        $this->throttle = new Throttle($this->store);
    }

    public function test_requests_under_the_limit_are_allowed(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->assertFalse($this->throttle->tooManyRequests('enter', 1, 5, 60));
        }
    }

    public function test_the_sixth_request_over_a_limit_of_five_is_blocked(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->throttle->tooManyRequests('enter', 1, 5, 60);
        }

        $this->assertTrue($this->throttle->tooManyRequests('enter', 1, 5, 60));
    }

    public function test_buckets_are_isolated_per_actor_and_per_action(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->throttle->tooManyRequests('enter', 1, 5, 60);
        }

        // Same action, different actor → allowed.
        $this->assertFalse($this->throttle->tooManyRequests('enter', 2, 5, 60));
        // Same actor, different action → allowed.
        $this->assertFalse($this->throttle->tooManyRequests('draw', 1, 5, 60));
        // Same everything → blocked.
        $this->assertTrue($this->throttle->tooManyRequests('enter', 1, 5, 60));
    }

    public function test_retry_after_reports_the_remaining_window(): void
    {
        $this->throttle->tooManyRequests('enter', 7, 5, 120);

        $after = $this->throttle->retryAfter('enter', 7);

        $this->assertGreaterThan(0, $after);
        $this->assertLessThanOrEqual(120, $after);
    }

    public function test_a_broken_cache_degrades_open_instead_of_throwing(): void
    {
        $broken = new class extends Repository {
            public function __construct()
            {
            }

            public function get($key, $default = null): mixed
            {
                throw new \RuntimeException('cache backend down');
            }

            public function put($key, $value, $ttl = null): bool
            {
                throw new \RuntimeException('cache backend down');
            }
        };

        $throttle = new Throttle($broken);

        $this->assertFalse($throttle->tooManyRequests('enter', 1, 1, 60));
    }
}
