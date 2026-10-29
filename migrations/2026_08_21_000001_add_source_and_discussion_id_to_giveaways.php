<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

return Migration::addColumns('giveaways', [
    'source' => ['string', 'nullable' => true, 'length' => 50],
    'discussion_id' => ['integer', 'unsigned' => true, 'nullable' => true],
]);
