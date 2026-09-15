<?php

namespace Ygpynet\Giveaways\Support;

use Illuminate\Contracts\Cache\Repository as CacheRepository;

/**
 * Minimal fixed-window rate limiter on top of Flarum's cache store — no new
 * dependency, works on every cache driver, and degrades open if the cache is
 * down (availability beats strictness for a community forum).
 *
 * Approximate by design: the counter is not atomic across the read/write, so
 * a few extra requests can slip through a hard concurrent burst. That is
 * fine for its purpose (blunting scripted abuse of enter/draw endpoints),
 * not for money-grade limits.
 */
class Throttle
{
    public function __construct(protected CacheRepository $cache)
    {
    }

    /**
     * Register one hit and report whether the caller has exceeded the limit.
     *
     * @param string $bucket logical action name (e.g. "enter", "draw")
     * @param int    $actorId the acting user id
     * @param int    $max     allowed hits per window
     * @param int    $window  window size in seconds
     */
    public function tooManyRequests(string $bucket, int $actorId, int $max, int $window): bool
    {
        $key = "ygpynet-giveaways.throttle.$bucket.$actorId";
        $now = time();

        try {
            $item = $this->cache->get($key);

            if (! is_array($item) || ($item['reset'] ?? 0) <= $now) {
                $item = ['count' => 0, 'reset' => $now + $window];
            }

            $item['count']++;
            $this->cache->put($key, $item, $item['reset'] - $now);

            return $item['count'] > $max;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Seconds until the current window resets (for Retry-After). */
    public function retryAfter(string $bucket, int $actorId): int
    {
        try {
            $item = $this->cache->get("ygpynet-giveaways.throttle.$bucket.$actorId");
            if (is_array($item) && ($item['reset'] ?? 0) > time()) {
                return (int) ($item['reset'] - time());
            }
        } catch (\Throwable $e) {
            // fall through
        }

        return 1;
    }
}
