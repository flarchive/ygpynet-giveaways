<?php

namespace ErnestDefoe\Giveaways\Event;

use ErnestDefoe\Giveaways\Giveaway;

/**
 * Fired after a draw commits and before winner notifications are sent.
 * $winnerUserIds is in draw order (position 1 first).
 */
class GiveawayWasDrawn
{
    /**
     * @param int[] $winnerUserIds
     */
    public function __construct(
        public readonly Giveaway $giveaway,
        public readonly array $winnerUserIds
    ) {
    }
}
