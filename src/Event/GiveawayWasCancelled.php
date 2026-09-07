<?php

namespace ErnestDefoe\Giveaways\Event;

use ErnestDefoe\Giveaways\Giveaway;

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
