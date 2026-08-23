<?php

use Flarum\Database\Migration;

return Migration::addColumns('giveaways', [
    'description_html' => ['text', 'nullable' => true]
]);