<?php

/*
 * This file is part of ygpynet/giveaways.
 *
 * Licensed under the MIT license.
 */

namespace Ygpynet\Giveaways\Tests\Integration;

use Carbon\Carbon;
use Flarum\Testing\integration\TestCase;
use Ygpynet\Giveaways\Contract\PointsGateway;
use Ygpynet\Giveaways\Tests\Integration\Support\MemoryPointsGateway;
use Ygpynet\Giveaways\Tests\Integration\Support\SwapPointsGateway;
use Ygpynet\Giveaways\Giveaway;
use Ygpynet\Giveaways\GiveawayEntry;

/**
 * Shared base for giveaways integration tests: boots the forum with THIS
 * extension enabled and offers fixtures for giveaways/entries. Points are
 * backed by MemoryPointsGateway so paid-giveaway flows run deterministically
 * without ramon/point-system.
 */
abstract class GiveawayTestCase extends TestCase
{
    /**
     * Register member rows BEFORE the app boots. Must be called before
     * bootWithGiveaways(): users are inserted during the fixture-populate
     * phase (foreign keys off), while later model writes run with MySQL's
     * FK constraints enforced.
     *
     * @param int ...$ids user ids to create (admin id 1 always exists)
     */
    protected function addMembers(int ...$ids): void
    {
        $rows = array_map([$this, 'member'], $ids ?: [2]);

        $this->prepareDatabase(['users' => $rows]);
    }

    protected function bootWithGiveaways(array $extraExtenders = []): void
    {
        // Boot with a fresh, empty in-memory points ledger per test.
        MemoryPointsGateway::reset();
        Support\FailingAwardGateway::$fail = false;
        $this->extend(new SwapPointsGateway());

        foreach ($extraExtenders as $extender) {
            $this->extend($extender);
        }

        $this->extension('ygpynet-giveaways');

        $this->app();
    }

    protected function gateway(): PointsGateway
    {
        return $this->app()->getContainer()->make(PointsGateway::class);
    }

    protected function createGiveaway(array $attributes = [], array $settings = []): Giveaway
    {
        $giveaway = new Giveaway();

        $giveaway->title = $attributes['title'] ?? 'Test giveaway';
        $giveaway->slug = $attributes['slug'] ?? ('test-'.bin2hex(random_bytes(4)));
        $giveaway->prize = $attributes['prize'] ?? 'A prize';
        $giveaway->winner_count = $attributes['winner_count'] ?? 1;
        $giveaway->status = $attributes['status'] ?? Giveaway::STATUS_ACTIVE;
        $giveaway->user_id = $attributes['user_id'] ?? 1;
        $giveaway->starts_at = isset($attributes['starts_at']) ? Carbon::parse($attributes['starts_at']) : null;
        $giveaway->ends_at = Carbon::parse($attributes['ends_at'] ?? '+1 hour');
        $giveaway->settings = $settings ? json_encode($settings) : null;
        $giveaway->created_at = Carbon::now();
        $giveaway->save();

        return $giveaway;
    }

    protected function forceEntry(int $giveawayId, int $userId, int $entries = 1, int $paidAmount = 0): GiveawayEntry
    {
        $entry = new GiveawayEntry();
        $entry->giveaway_id = $giveawayId;
        $entry->user_id = $userId;
        $entry->entries = $entries;
        $entry->paid_amount = $paidAmount;
        $entry->sources = json_encode(['base' => $entries]);
        $entry->created_at = Carbon::now();
        $entry->updated_at = Carbon::now();
        $entry->save();

        return $entry;
    }

    protected function member(int $id = 2): array
    {
        return [
            'id' => $id,
            'username' => 'member'.$id,
            'email' => "member$id@machine.local",
            'password' => '$2y$10$LO59tiT7uggl6Oe23o/O6.utnF6ipngYjvMvaxo1TciKqBttDNKim',
            'is_email_confirmed' => 1,
        ];
    }
}
