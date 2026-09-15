<?php

/*
 * This file is part of ygpynet/giveaways.
 *
 * Licensed under the MIT license.
 */

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

// Durable queue for point refunds that failed at refund time (e.g. the points
// system was down). `giveaways:retry-refunds` drains it so a money-moving
// failure is never just a log line. Deliberately NO foreign keys: an audit
// record must survive deletion of the giveaway or the user account.
return Migration::createTable('giveaway_refunds', function (Blueprint $table) {
    $table->increments('id');
    $table->unsignedInteger('giveaway_id');
    $table->unsignedInteger('user_id');
    $table->unsignedInteger('amount');
    $table->string('reason', 50);
    $table->unsignedTinyInteger('attempts')->default(0);
    $table->string('last_error')->nullable();
    $table->dateTime('created_at')->nullable();
    $table->dateTime('refunded_at')->nullable();

    $table->index(['giveaway_id', 'user_id']);
    $table->index('refunded_at');
});
