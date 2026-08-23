<?php

namespace ErnestDefoe\Giveaways\Tests;

use Carbon\Carbon;
use ErnestDefoe\Giveaways\Giveaway;
use PHPUnit\Framework\TestCase;

class GiveawayTest extends TestCase
{
    public function testSettingsArrayReturnsDefaultsWhenEmpty(): void
    {
        $g = new Giveaway();

        $this->assertSame([
            'post_bonus'         => 0,
            'min_posts'          => 0,
            'min_age_days'       => 0,
            'claim_instructions' => '',
        ], $g->settingsArray());
    }

    public function testSettingsArrayMergesStoredValuesWithDefaults(): void
    {
        $g = new Giveaway();
        $g->settings = json_encode(['post_bonus' => 5, 'min_posts' => 10]);

        $s = $g->settingsArray();

        $this->assertSame(5, $s['post_bonus']);
        $this->assertSame(10, $s['min_posts']);
        $this->assertSame(0, $s['min_age_days']);
        $this->assertSame('', $s['claim_instructions']);
    }

    public function testSettingsArrayFallsBackToDefaultsOnMalformedJson(): void
    {
        $g = new Giveaway();
        $g->settings = '{not valid json';

        $this->assertSame(0, $g->settingsArray()['post_bonus']);
        $this->assertSame('', $g->settingsArray()['claim_instructions']);
    }

    public function testActiveWithinWindowIsRunning(): void
    {
        $g = new Giveaway();
        $g->setRawAttributes([
            'status'    => 'active',
            'starts_at' => Carbon::parse('-1 hour'),
            'ends_at'   => Carbon::parse('+1 day'),
        ]);

        $this->assertTrue($g->isRunning());
    }

    public function testNotYetStartedIsNotRunning(): void
    {
        $g = new Giveaway();
        $g->setRawAttributes([
            'status'    => 'active',
            'starts_at' => Carbon::parse('+1 hour'),
            'ends_at'   => Carbon::parse('+2 days'),
        ]);

        $this->assertFalse($g->isRunning());
    }

    public function testEndedGiveawayIsNotRunning(): void
    {
        $g = new Giveaway();
        $g->setRawAttributes([
            'status'  => 'active',
            'ends_at' => Carbon::parse('-1 minute'),
        ]);

        $this->assertFalse($g->isRunning());
    }

    public function testNonActiveStatusIsNotRunning(): void
    {
        $g = new Giveaway();
        $g->setRawAttributes([
            'status'    => 'drawn',
            'starts_at' => null,
            'ends_at'   => Carbon::parse('+1 day'),
        ]);

        $this->assertFalse($g->isRunning());
    }

    public function testActiveWithinWindowHasNotEnded(): void
    {
        $g = new Giveaway();
        $g->setRawAttributes([
            'status'    => 'active',
            'starts_at' => Carbon::parse('-1 hour'),
            'ends_at'   => Carbon::parse('+1 day'),
        ]);

        $this->assertFalse($g->hasEnded());
    }

    public function testNotYetStartedHasNotEnded(): void
    {
        $g = new Giveaway();
        $g->setRawAttributes([
            'status'    => 'active',
            'starts_at' => Carbon::parse('+1 hour'),
            'ends_at'   => Carbon::parse('+2 days'),
        ]);

        $this->assertFalse($g->hasEnded());
    }

    public function testExpiredWindowHasEnded(): void
    {
        $g = new Giveaway();
        $g->setRawAttributes([
            'status'  => 'active',
            'ends_at' => Carbon::parse('-1 minute'),
        ]);

        $this->assertTrue($g->hasEnded());
    }

    public function testDrawnHasEnded(): void
    {
        $g = new Giveaway();
        $g->setRawAttributes([
            'status'  => 'drawn',
            'ends_at' => Carbon::parse('+1 day'),
        ]);

        $this->assertTrue($g->hasEnded());
    }

    public function testCancelledHasEnded(): void
    {
        $g = new Giveaway();
        $g->setRawAttributes([
            'status'  => 'cancelled',
            'ends_at' => Carbon::parse('+1 day'),
        ]);

        $this->assertTrue($g->hasEnded());
    }
}