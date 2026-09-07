<?php

namespace ErnestDefoe\Giveaways\Event;

use ErnestDefoe\Giveaways\Giveaway;
use ErnestDefoe\Giveaways\GiveawayEntry;
use Flarum\User\User;

/**
 * Fired after a base entry is durably written (transaction committed).
 * Listeners may safely query persisted state.
 */
class GiveawayWasEntered
{
    public function __construct(
        public readonly Giveaway $giveaway,
        public readonly User $user,
        public readonly GiveawayEntry $entry
    ) {
    }
}
