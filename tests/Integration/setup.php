<?php

/*
 * This file is part of ygpynet/giveaways.
 *
 * Licensed under the MIT license.
 */

use Flarum\Testing\integration\Setup\SetupScript;

require __DIR__.'/../../vendor/autoload.php';

// One-time local forum installation for the integration suite (SQLite by
// default; override with DB_DRIVER/DB_HOST/... env vars). Re-run after
// deleting the tmp dir (vendor/flarum/testing/src/integration/tmp, or
// FLARUM_TEST_TMP_DIR if set).
$setup = new SetupScript();

$setup->run();
