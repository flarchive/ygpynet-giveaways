<?php

/*
 * This file is part of ygpynet/giveaways.
 *
 * Licensed under the MIT license.
 */

namespace Ygpynet\Giveaways\Tests\Integration;

use Ygpynet\Giveaways\Giveaway;

/**
 * Proves the test forum boots with the extension enabled and that the
 * migration-created schema is reachable through the models.
 */
class SmokeTest extends GiveawayTestCase
{
    public function test_forum_boots_and_extension_tables_are_writable(): void
    {
        $this->bootWithGiveaways();

        $giveaway = $this->createGiveaway(['title' => 'Smoke']);

        $this->assertNotNull($giveaway->id);
        $this->assertSame('Smoke', Giveaway::find($giveaway->id)->title);
    }

    public function test_entries_and_refund_tables_exist(): void
    {
        $this->addMembers(2);
        $this->bootWithGiveaways();

        $giveaway = $this->createGiveaway();
        $this->forceEntry($giveaway->id, 2, 3, 5);

        $this->assertSame(1, $giveaway->entries()->count());

        // giveaway_refunds table must exist (empty is fine at this point).
        $this->assertSame(0, $this->database()->table('giveaway_refunds')->count());
    }
}
