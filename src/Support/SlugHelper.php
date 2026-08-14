<?php

namespace ErnestDefoe\Giveaways\Support;

use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

/**
 * Shared slug generation + safe-save helpers used by giveaways and categories.
 *
 * The unique checks are injected so each caller queries its own table (and can
 * exclude its own id on update); the base-slug logic itself is pure and unit
 * testable. The save helper retries on a duplicate-key violation, which closes
 * the classic check-then-insert race on slug columns that carry a unique index.
 */
class SlugHelper
{
    public static function base(string $title, string $fallback = 'giveaway'): string
    {
        return Str::slug($title) ?: $fallback;
    }

    /**
     * @param callable(string $slug): bool $exists  returns true when the slug is taken
     */
    public static function unique(string $title, callable $exists, string $fallback = 'giveaway'): string
    {
        $base = self::base($title, $fallback);
        $slug = $base;
        $i = 2;

        while ($exists($slug)) {
            $slug = $base . '-' . $i++;
        }

        return $slug;
    }

    /**
     * Save $save(); on a duplicate-key error (a concurrent insert won the slug
     * race), call $regenerateSlug() and retry, at most a bounded number of
     * times. Any other error is rethrown untouched.
     *
     * @param callable(): mixed         $save
     * @param callable(): mixed         $regenerateSlug
     */
    public static function saveWithUniqueSlug(callable $save, callable $regenerateSlug): void
    {
        $attempts = 5;

        while (true) {
            try {
                $save();

                return;
            } catch (QueryException $e) {
                if (--$attempts <= 0 || ! self::isDuplicateKey($e)) {
                    throw $e;
                }

                $regenerateSlug();
            }
        }
    }

    public static function isDuplicateKey(QueryException $e): bool
    {
        // MySQL reports duplicate entries as SQLSTATE 23000 with driver code 1062;
        // SQLite reports 1555/2067 and names the constraint in the message.
        return (is_array($e->errorInfo)
                && isset($e->errorInfo[1])
                && (int) $e->errorInfo[1] === 1062)
            || str_contains(strtoupper($e->getMessage()), 'UNIQUE');
    }
}