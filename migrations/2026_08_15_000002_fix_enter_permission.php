<?php

use Flarum\Database\Migration;

// Repair: the original 000004 migration passed the group NAME ('member') instead
// of the numeric group id, so Flarum silently skipped the insert and
// `giveaways.enter` was never granted to anyone (non-admins couldn't enter).
// Members are group id 3.
return Migration::addPermissions([
    'giveaways.enter' => [3],
]);
