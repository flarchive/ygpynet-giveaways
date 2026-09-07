<?php

namespace ErnestDefoe\Giveaways\Tests;

use ErnestDefoe\Giveaways\Giveaway;
use PHPUnit\Framework\TestCase;

/**
 * The status machine guards every lifecycle mutation. drawn/cancelled must be
 * terminal; only DrawService may perform active → drawn; cancellation is
 * legal from draft/active but never from a terminal state.
 */
class GiveawayStateMachineTest extends TestCase
{
    private function in(string $status): Giveaway
    {
        $g = new Giveaway();
        $g->setRawAttributes(['status' => $status]);
        return $g;
    }

    public function testDraftCanPublishCancelOrStay(): void
    {
        $this->assertTrue($this->in('draft')->canTransitionTo('draft'));
        $this->assertTrue($this->in('draft')->canTransitionTo('active'));
        $this->assertTrue($this->in('draft')->canTransitionTo('cancelled'));
        $this->assertFalse($this->in('draft')->canTransitionTo('drawn'));
    }

    public function testActiveCanOnlyDrawOrCancel(): void
    {
        $this->assertTrue($this->in('active')->canTransitionTo('drawn'));
        $this->assertTrue($this->in('active')->canTransitionTo('cancelled'));
        $this->assertFalse($this->in('active')->canTransitionTo('draft'));
        $this->assertFalse($this->in('active')->canTransitionTo('active'));
    }

    public function testDrawnIsTerminal(): void
    {
        foreach (['draft', 'active', 'drawn', 'cancelled'] as $to) {
            $this->assertFalse($this->in('drawn')->canTransitionTo($to));
        }
    }

    public function testCancelledIsTerminal(): void
    {
        foreach (['draft', 'active', 'drawn', 'cancelled'] as $to) {
            $this->assertFalse($this->in('cancelled')->canTransitionTo($to));
        }
    }

    public function testUnknownCurrentStatusAllowsNothing(): void
    {
        $this->assertFalse($this->in('bogus')->canTransitionTo('active'));
    }

    public function testOnlyDraftAndActiveAreEditable(): void
    {
        $this->assertTrue($this->in('draft')->isEditable());
        $this->assertTrue($this->in('active')->isEditable());
        $this->assertFalse($this->in('drawn')->isEditable());
        $this->assertFalse($this->in('cancelled')->isEditable());
    }
}
