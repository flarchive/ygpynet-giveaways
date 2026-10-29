<?php

namespace Ygpynet\Giveaways\Event;

use Ygpynet\Giveaways\Giveaway;

/**
 * Fired after a giveaway is atomically cancelled (draft/active → cancelled)
 * and entry-fee refunds have been issued.
 */
class GiveawayWasCancelled
{
    public function __construct(public readonly Giveaway $giveaway)
    {
    }
}
