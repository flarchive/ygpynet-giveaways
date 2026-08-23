<?php

namespace ErnestDefoe\Giveaways\Event;

use ErnestDefoe\Giveaways\Giveaway;
use Flarum\User\User;

/** Fired when a winner claims their prize (first claim only). */
class GiveawayWasClaimed
{
    public function __construct(
        public readonly Giveaway $giveaway,
        public readonly User $winner
    ) {
    }
}
