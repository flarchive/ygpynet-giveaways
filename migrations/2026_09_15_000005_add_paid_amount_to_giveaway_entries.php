<?php

/*
 * This file is part of ygpynet/giveaways.
 *
 * Licensed under the MIT license.
 */

use Flarum\Database\Migration;

// Fund audit: entries now record the exact points amount charged at entry
// time, so cancellation refunds return what the user actually paid instead of
// the (possibly edited) current entry_cost_points setting.
$addColumns = Migration::addColumns('giveaway_entries', [
    'paid_amount' => ['integer', 'unsigned' => true, 'default' => 0],
]);

return [
    'up' => function ($schema) use ($addColumns) {
        ($addColumns['up'])($schema);

        // Best-effort backfill for legacy rows: every entry in a paid giveaway
        // was charged the fee in effect at entry time; the current setting is
        // the closest figure we have. Rows created after this migration always
        // carry the true charged amount, written by EntryService.
        $db = $schema->getConnection();

        $db->table('giveaways')
            ->whereNotNull('settings')
            ->chunkById(100, function ($giveaways) use ($db) {
                foreach ($giveaways as $g) {
                    $settings = json_decode((string) $g->settings, true) ?: [];
                    $cost = (int) ($settings['entry_cost_points'] ?? 0);
                    if ($cost > 0) {
                        $db->table('giveaway_entries')
                            ->where('giveaway_id', $g->id)
                            ->where('paid_amount', 0)
                            ->update(['paid_amount' => $cost]);
                    }
                }
            }, 'id');
    },
    'down' => function ($schema) use ($addColumns) {
        ($addColumns['down'])($schema);
    },
];
