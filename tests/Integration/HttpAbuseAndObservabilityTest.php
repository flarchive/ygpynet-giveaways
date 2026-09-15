<?php

/*
 * This file is part of ygpynet/giveaways.
 *
 * Licensed under the MIT license.
 */

namespace Ygpynet\Giveaways\Tests\Integration;

/**
 * Abuse-suppression and observability surfaces, exercised through the FULL
 * middleware stack (real rate limiting, real auth, real JSON).
 */
class HttpAbuseAndObservabilityTest extends GiveawayTestCase
{
    /**
     * The throttle window is fixed (rate limit is a constant, not a setting);
     * we only assert the boundary behaviour: the last of 32 identical
     * requests must be a 429 with the giveaways error code.
     */
    public function test_a_scripted_entry_burst_is_stopped_with_429(): void
    {
        $this->addMembers(2);
        $this->bootWithGiveaways();

        $giveaway = $this->createGiveaway();

        $codes = [];
        $last = null;

        for ($i = 0; $i < 32; $i++) {
            $last = $this->send($this->request('POST', "/api/giveaways/{$giveaway->id}/enter", [
                'authenticatedAs' => 2,
            ]));
            $codes[] = $last->getStatusCode();
        }

        $this->assertSame(200, $codes[0], 'the first enter must succeed');
        $this->assertSame(429, $last->getStatusCode(), 'the burst must hit the limit');

        $body = json_decode((string) $last->getBody(), true);
        $this->assertSame('giveaways.rate_limited', $body['errors'][0]['code'] ?? null);
    }

    public function test_rate_limiting_is_per_actor(): void
    {
        $this->addMembers(2, 3);
        $this->bootWithGiveaways();

        $giveaway = $this->createGiveaway();

        // User 2 exhausts nothing; user 3 sends 31 — only user 3 should be blocked.
        $this->send($this->request('POST', "/api/giveaways/{$giveaway->id}/enter", ['authenticatedAs' => 2]));

        for ($i = 0; $i < 31; $i++) {
            $blocked = $this->send($this->request('POST', "/api/giveaways/{$giveaway->id}/enter", ['authenticatedAs' => 3]));
        }

        $this->assertSame(429, $blocked->getStatusCode());

        $untouched = $this->send($this->request('POST', "/api/giveaways/{$giveaway->id}/enter", ['authenticatedAs' => 2]));
        $this->assertSame(200, $untouched->getStatusCode(), "user 2's bucket is separate");
    }

    public function test_health_endpoint_reports_operational_diagnostics(): void
    {
        $this->addMembers(2);
        $this->bootWithGiveaways();

        $body0 = $this->health();

        // One giveaway becomes overdue for its automatic draw.
        $this->createGiveaway(['ends_at' => '-10 minutes']);
        // One failed refund is queued.
        $giveaway = $this->createGiveaway();
        \Ygpynet\Giveaways\GiveawayRefund::record((int) $giveaway->id, 2, 5, 'giveaway.entry.refund', 'boom');

        $body = $this->health();

        $this->assertArrayHasKey('scheduleLastRun', $body);
        $this->assertFalse($body['schemaOutdated']);
        $this->assertIsBool($body['pointsAvailable']);
        $this->assertSame($body0['pendingDraws'] + 1, $body['pendingDraws']);
        $this->assertNotNull($body['oldestDueAt']);
        $this->assertSame($body0['pendingRefunds'] + 1, $body['pendingRefunds']);
    }

    /**
     * After an upgrade but BEFORE `php flarum migrate`, the new refund table
     * does not exist yet — the diagnostics endpoint is exactly what the admin
     * sees at that moment, so it must degrade gracefully instead of 500ing.
     *
     * Simulated with a scoped query against a nonexistent table (a plain DML
     * error — no DDL, so no implicit commit and no schema churn): the Query-
     * Exception must be caught by HealthController and reported as
     * schemaOutdated. Global scopes are per-process; processIsolation keeps
     * this out of every other test.
     */
    public function test_health_degrades_instead_of_500ing_when_the_schema_is_outdated(): void
    {
        $this->addMembers(2);
        $this->bootWithGiveaways();

        $this->createGiveaway(['ends_at' => '-10 minutes']);

        \Ygpynet\Giveaways\GiveawayRefund::addGlobalScope(
            'simulated-missing-table',
            fn ($query) => $query->where('giveaway_refunds_not_migrated_yet.refunded_at', null)
        );

        $response = $this->send($this->request('GET', '/api/giveaways/health', ['authenticatedAs' => 1]));

        $this->assertSame(200, $response->getStatusCode(), 'a missing table must never 500 the health endpoint');

        $body = json_decode((string) $response->getBody(), true);

        $this->assertTrue($body['schemaOutdated'], 'the missing table must be reported, not fatal');
        $this->assertNull($body['pendingRefunds']);
        $this->assertIsInt($body['pendingDraws'], 'queries whose tables exist keep working');
    }

    protected function health(): array
    {
        $response = $this->send($this->request('GET', '/api/giveaways/health', ['authenticatedAs' => 1]));

        $this->assertSame(200, $response->getStatusCode());

        return json_decode((string) $response->getBody(), true);
    }

    public function test_health_endpoint_is_admin_only(): void
    {
        $this->addMembers(2);
        $this->bootWithGiveaways();

        $response = $this->send($this->request('GET', '/api/giveaways/health', ['authenticatedAs' => 2]));

        $this->assertSame(403, $response->getStatusCode());
    }

    /**
     * Bug isolation (regression): drafts are private everywhere else, but the
     * entries endpoint only checked the (member-default) viewEntries grant —
     * so any member could probe another user's draft by id. Drafts have no
     * entries yet, so the leak was structural (id/status oracle), not data.
     */
    public function test_entries_of_someone_elses_draft_are_not_readable_with_view_entries(): void
    {
        $this->addMembers(2);
        $this->bootWithGiveaways();

        // Admin-owned draft (user_id 1), member 2 holds giveaways.viewEntries.
        $draft = $this->createGiveaway(['status' => \Ygpynet\Giveaways\Giveaway::STATUS_DRAFT, 'user_id' => 1]);

        $response = $this->send($this->request('GET', "/api/giveaways/{$draft->id}/entries", ['authenticatedAs' => 2]));

        $this->assertContains($response->getStatusCode(), [403, 404], 'a private draft must not be enumerable');
    }
}
