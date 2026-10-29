<?php

/*
 * This file is part of ygpynet/giveaways.
 *
 * Licensed under the MIT license.
 */

namespace Ygpynet\Giveaways\Tests\Integration;

use Flarum\Testing\integration\ConsoleTestCase;
use Ygpynet\Giveaways\DrawService;
use Ygpynet\Giveaways\Giveaway;
use Ygpynet\Giveaways\GiveawayRefund;
use Ygpynet\Giveaways\Tests\Integration\Support\FailingAwardGateway;
use Ygpynet\Giveaways\Tests\Integration\Support\SwapFailingGateway;
use Ygpynet\Giveaways\Tests\Integration\Support\SwapPointsGateway;
use Ygpynet\Giveaways\Tests\Integration\Support\MemoryPointsGateway;

/**
 * The two operational commands, run through the real console application.
 */
class ConsoleCommandsTest extends ConsoleTestCase
{
    protected function bootGiveaways(array $extraExtenders = []): void
    {
        MemoryPointsGateway::reset();
        FailingAwardGateway::$fail = false;

        $this->extend(new SwapPointsGateway());

        foreach ($extraExtenders as $extender) {
            $this->extend($extender);
        }

        $this->extension('ygpynet-giveaways');
        $this->app();
    }

    protected function drawnGiveaway(): Giveaway
    {
        $g = new Giveaway();
        $g->title = 'Console draw';
        $g->slug = 'console-'.bin2hex(random_bytes(4));
        $g->prize = 'Prize';
        $g->winner_count = 1;
        $g->status = Giveaway::STATUS_ACTIVE;
        $g->user_id = 1;
        $g->ends_at = \Carbon\Carbon::now()->subMinute();
        $g->created_at = \Carbon\Carbon::now();
        $g->save();

        foreach ([2, 3, 4] as $uid) {
            $e = new \Ygpynet\Giveaways\GiveawayEntry();
            $e->giveaway_id = $g->id;
            $e->user_id = $uid;
            $e->entries = 1;
            $e->sources = json_encode(['base' => 1]);
            $e->created_at = \Carbon\Carbon::now();
            $e->save();
        }

        $this->app()->getContainer()->make(DrawService::class)->draw(Giveaway::find($g->id));

        return Giveaway::find($g->id);
    }

    public function test_verify_reports_ok_for_an_honest_draw(): void
    {
        $this->prepareDatabase([
            'users' => [
                ['id' => 2, 'username' => 'member2', 'email' => 'm2@machine.local', 'is_email_confirmed' => 1],
                ['id' => 3, 'username' => 'member3', 'email' => 'm3@machine.local', 'is_email_confirmed' => 1],
                ['id' => 4, 'username' => 'member4', 'email' => 'm4@machine.local', 'is_email_confirmed' => 1],
            ],
        ]);
        $this->bootGiveaways();

        $giveaway = $this->drawnGiveaway();

        $output = $this->runCommand(['command' => 'giveaways:verify', 'id' => (string) $giveaway->id]);

        $this->assertStringContainsString('VERIFIED', $output);
        $this->assertStringContainsString('[MATCH]', $output);
    }

    public function test_verify_detects_a_tampered_winner(): void
    {
        $this->prepareDatabase([
            'users' => [
                ['id' => 2, 'username' => 'member2', 'email' => 'm2@machine.local', 'is_email_confirmed' => 1],
                ['id' => 3, 'username' => 'member3', 'email' => 'm3@machine.local', 'is_email_confirmed' => 1],
                ['id' => 4, 'username' => 'member4', 'email' => 'm4@machine.local', 'is_email_confirmed' => 1],
            ],
        ]);
        $this->bootGiveaways();

        $giveaway = $this->drawnGiveaway();

        // Swap the winner for an entrant the seed does not select.
        $recorded = (int) $this->database()->table('giveaway_winners')->where('giveaway_id', $giveaway->id)->value('user_id');
        $other = in_array(2, [$recorded], true) ? 3 : 2;
        $this->database()->table('giveaway_winners')->where('giveaway_id', $giveaway->id)->update(['user_id' => $other]);

        $output = $this->runCommand(['command' => 'giveaways:verify', 'id' => (string) $giveaway->id]);

        $this->assertStringContainsString('TAMPER', $output);
        $this->assertStringContainsString('stored winners do not match the seed', $output);
    }

    public function test_verify_rejects_a_non_drawn_giveaway(): void
    {
        $this->bootGiveaways();

        $g = new Giveaway();
        $g->title = 'Still open';
        $g->slug = 'open-'.bin2hex(random_bytes(4));
        $g->prize = 'P';
        $g->winner_count = 1;
        $g->status = Giveaway::STATUS_ACTIVE;
        $g->user_id = 1;
        $g->ends_at = \Carbon\Carbon::now()->addHour();
        $g->created_at = \Carbon\Carbon::now();
        $g->save();

        $output = $this->runCommand(['command' => 'giveaways:verify', 'id' => (string) $g->id]);

        $this->assertStringContainsString('not drawn', $output);
    }

    public function test_retry_refunds_command_drains_the_queue(): void
    {
        $this->prepareDatabase([
            'users' => [
                ['id' => 2, 'username' => 'member2', 'email' => 'm2@machine.local', 'is_email_confirmed' => 1],
            ],
        ]);
        $this->bootGiveaways([new SwapFailingGateway()]);

        // Queue a refund while the gateway fails awards...
        FailingAwardGateway::$fail = true;
        GiveawayRefund::record(1, 2, 40, 'giveaway.entry.refund', 'gateway down');

        // ...then the gateway recovers and the command settles it.
        FailingAwardGateway::$fail = false;

        $output = $this->runCommand(['command' => 'giveaways:retry-refunds']);

        $this->assertStringContainsString('Refunded: 1', $output);
        $this->assertSame(0, GiveawayRefund::pending()->count());
        $this->assertSame(140, MemoryPointsGateway::balance(2), 'the 40-point credit landed exactly once');
    }

    public function test_retry_refunds_reports_still_failing_rows(): void
    {
        $this->prepareDatabase([
            'users' => [
                ['id' => 2, 'username' => 'member2', 'email' => 'm2@machine.local', 'is_email_confirmed' => 1],
            ],
        ]);
        $this->bootGiveaways([new SwapFailingGateway()]);

        FailingAwardGateway::$fail = true;
        GiveawayRefund::record(1, 2, 40, 'giveaway.entry.refund', 'gateway down');

        $output = $this->runCommand(['command' => 'giveaways:retry-refunds']);

        $this->assertStringContainsString('still pending: 1', $output);
        $this->assertSame(1, GiveawayRefund::pending()->count());
        $this->assertSame(1, (int) GiveawayRefund::pending()->first()->attempts, 'the failed attempt was counted');
    }
}
