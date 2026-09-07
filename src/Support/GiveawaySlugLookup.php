<?php

namespace ErnestDefoe\Giveaways\Support;

use ErnestDefoe\Giveaways\Giveaway;

/**
 * Per-request memoized slug → giveaway lookup.
 *
 * The discussion-list title override runs once per discussion row; without
 * this cache a single page of discussions whose posts contain
 * "[giveaway slug=…]" tags would fire one identical query per row — an easy
 * DB amplification vector via crafted post content.
 *
 * The static array lives exactly one PHP request under FPM, so entries can
 * never leak across users; writers flush explicitly (see Save/Delete
 * controllers) so a save followed by a re-render in the same request still
 * sees fresh data.
 */
class GiveawaySlugLookup
{
    /** @var array<string, Giveaway|null> */
    protected static array $cache = [];

    public static function find(string $slug): ?Giveaway
    {
        if (! array_key_exists($slug, self::$cache)) {
            // Bound the cache per request: a page can reference many distinct
            // slugs, but far fewer than any meaningful memory limit.
            if (count(self::$cache) >= 100) {
                self::$cache = [];
            }
            self::$cache[$slug] = Giveaway::query()->where('slug', $slug)->first();
        }

        return self::$cache[$slug];
    }

    public static function flush(): void
    {
        self::$cache = [];
    }
}
