<?php

namespace ErnestDefoe\Giveaways\Tests;

use Carbon\Carbon;
use ErnestDefoe\Giveaways\EntryService;
use ErnestDefoe\Giveaways\Giveaway;
use Flarum\Locale\TranslatorInterface;
use Flarum\User\User;
use PHPUnit\Framework\TestCase;

/**
 * A closed giveaway must tell the user why it's closed: a giveaway whose
 * status is still 'active' but has ended reads very differently from one that
 * hasn't opened yet, or one that's already been drawn/cancelled.
 */
class EntryServiceTest extends TestCase
{
    private function service(): EntryService
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(fn (string $key, array $params = []) => $key);
        return new EntryService($translator);
    }

    private function user(): User
    {
        $user = $this->createMock(User::class);
        $user->method('isGuest')->willReturn(false);
        return $user;
    }

    private function giveaway(array $attrs): Giveaway
    {
        $g = new Giveaway();
        $g->setRawAttributes($attrs);
        return $g;
    }

    public function testEndedGiveawayReturnsEndedMessage(): void
    {
        $g = $this->giveaway([
            'status'  => 'active',
            'ends_at' => Carbon::parse('-1 minute'),
        ]);

        $this->assertSame(
            'ernestdefoe-giveaways.api.enter_ended',
            $this->service()->ineligibleReason($g, $this->user())
        );
    }

    public function testNotYetStartedGiveawayReturnsClosedMessage(): void
    {
        $g = $this->giveaway([
            'status'    => 'active',
            'starts_at' => Carbon::parse('+1 hour'),
            'ends_at'   => Carbon::parse('+2 days'),
        ]);

        $this->assertSame(
            'ernestdefoe-giveaways.api.enter_closed',
            $this->service()->ineligibleReason($g, $this->user())
        );
    }

    public function testDrawnGiveawayReturnsDrawnMessage(): void
    {
        $g = $this->giveaway([
            'status'    => 'drawn',
            'starts_at' => null,
            'ends_at'   => Carbon::parse('+1 day'),
        ]);

        $this->assertSame(
            'ernestdefoe-giveaways.api.enter_drawn',
            $this->service()->ineligibleReason($g, $this->user())
        );
    }

    public function testCancelledGiveawayReturnsCancelledMessage(): void
    {
        $g = $this->giveaway([
            'status'    => 'cancelled',
            'starts_at' => null,
            'ends_at'   => Carbon::parse('+1 day'),
        ]);

        $this->assertSame(
            'ernestdefoe-giveaways.api.enter_cancelled',
            $this->service()->ineligibleReason($g, $this->user())
        );
    }

    public function testRunningGiveawayHasNoIneligibleReason(): void
    {
        $g = $this->giveaway([
            'status'    => 'active',
            'starts_at' => Carbon::parse('-1 hour'),
            'ends_at'   => Carbon::parse('+1 day'),
        ]);

        $this->assertNull($this->service()->ineligibleReason($g, $this->user()));
    }

    public function testEntryCostReadsFromSettings(): void
    {
        $g = $this->giveaway([
            'status'    => 'active',
            'starts_at' => Carbon::parse('-1 hour'),
            'ends_at'   => Carbon::parse('+1 day'),
            'settings'  => json_encode(['entry_cost_points' => 25]),
        ]);

        $this->assertSame(25, $this->service()->entryCost($g));
    }

    public function testFreeGiveawayHasZeroCost(): void
    {
        $g = $this->giveaway([
            'status'    => 'active',
            'starts_at' => Carbon::parse('-1 hour'),
            'ends_at'   => Carbon::parse('+1 day'),
        ]);

        $this->assertSame(0, $this->service()->entryCost($g));
    }

    /**
     * With no point system installed, a paid giveaway must refuse entry with
     * an explicit reason rather than silently entering for free.
     */
    public function testPaidGiveawayWithoutPointSystemIsIneligible(): void
    {
        if (\ErnestDefoe\Giveaways\Support\PointSystem::available()) {
            $this->markTestSkipped('ramon/point-system is installed');
        }

        $g = $this->giveaway([
            'status'    => 'active',
            'starts_at' => Carbon::parse('-1 hour'),
            'ends_at'   => Carbon::parse('+1 day'),
            'settings'  => json_encode(['entry_cost_points' => 10]),
        ]);

        $this->assertSame(
            'ernestdefoe-giveaways.api.enter_points_unavailable',
            $this->service()->ineligibleReason($g, $this->user())
        );
    }
}
