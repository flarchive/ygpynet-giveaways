<?php

use Flarum\Database\Migration;

// Members (group 3) can view post-draw winner details (claim instructions) out
// of the box; managers/owners and the winners themselves can always view them
// regardless of this permission (checked in GiveawayPresenter).
return Migration::addPermissions([
    'giveaways.viewWinners' => [3],
]);
