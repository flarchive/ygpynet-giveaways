<?php

use Flarum\Database\Migration;

// Members (group 3) can view the entrant list out of the box; managers/owners
// can always view it regardless of this permission (checked in
// ListEntriesController).
return Migration::addPermissions([
    'giveaways.viewEntries' => [3],
]);
