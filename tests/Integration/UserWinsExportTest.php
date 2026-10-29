<?php

/*
 * This file is part of ygpynet/giveaways.
 *
 * Licensed under the MIT license.
 */

namespace Ygpynet\Giveaways\Tests\Integration;

use Carbon\Carbon;
use Ygpynet\Giveaways\Giveaway;
use Ygpynet\Giveaways\GiveawayWinner;

/**
 * The per-user winnings export: members can download their own record, other
 * users' email addresses are exposed only to giveaway managers, and the CSV
 * carries every field a host needs to fulfil the prizes.
 */
class UserWinsExportTest extends GiveawayTestCase
{
    protected function win(Giveaway $giveaway, int $userId, int $position = 1, ?string $claimedAt = null): GiveawayWinner
    {
        $win = new GiveawayWinner();
        $win->giveaway_id = $giveaway->id;
        $win->user_id = $userId;
        $win->position = $position;
        $win->claimed_at = $claimedAt ? Carbon::parse($claimedAt) : null;
        $win->created_at = Carbon::now();
        $win->save();

        return $win;
    }

    public function test_a_member_can_export_their_own_wins_with_every_requested_field(): void
    {
        $this->addMembers(2);
        $this->bootWithGiveaways();

        $giveaway = $this->createGiveaway([
            'status' => Giveaway::STATUS_DRAWN,
            'title' => 'Spring raffle',
            'prize' => 'Golden Ticket',
        ], [
            'claim_instructions' => 'DM me your shipping address.',
        ]);

        $entry = $this->forceEntry($giveaway->id, 2);
        $entry->created_at = Carbon::parse('2026-01-02 03:04:05');
        $entry->save();

        $this->win($giveaway, 2, 1, '2026-01-05 06:07:08');

        $response = $this->send($this->request('GET', '/api/giveaways/wins/export', [
            'authenticatedAs' => 2,
        ]));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('text/csv', $response->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('attachment;', $response->getHeaderLine('Content-Disposition'));

        $body = (string) $response->getBody();

        foreach ([
            'member2@machine.local',
            'member2',
            'Spring raffle',
            'Golden Ticket',
            '2026-01-02 03:04:05',
            '2026-01-05 06:07:08',
            'DM me your shipping address.',
        ] as $expected) {
            $this->assertStringContainsString($expected, $body, "the export must contain {$expected}");
        }
    }

    public function test_a_member_cannot_export_someone_elses_wins(): void
    {
        $this->addMembers(2, 3);
        $this->bootWithGiveaways();

        $giveaway = $this->createGiveaway(['status' => Giveaway::STATUS_DRAWN]);
        $this->win($giveaway, 3);

        $response = $this->send(
            $this->request('GET', '/api/giveaways/wins/export', ['authenticatedAs' => 2])
                ->withQueryParams(['userId' => 3])
        );

        $this->assertSame(403, $response->getStatusCode());
    }

    public function test_a_manager_can_export_another_users_wins(): void
    {
        $this->addMembers(2);
        $this->bootWithGiveaways();

        $giveaway = $this->createGiveaway(['status' => Giveaway::STATUS_DRAWN]);
        $this->win($giveaway, 2);

        $response = $this->send(
            $this->request('GET', '/api/giveaways/wins/export', ['authenticatedAs' => 1]) // admin
                ->withQueryParams(['userId' => 2])
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('member2@machine.local', (string) $response->getBody());
    }

    public function test_the_forum_payload_tells_the_ui_who_may_export_someone_elses_wins(): void
    {
        $this->addMembers(2);
        $this->bootWithGiveaways();

        $member = $this->forumAttributes(2);
        $this->assertFalse($member['giveawaysCanManage']);

        $admin = $this->forumAttributes(1);
        $this->assertTrue($admin['giveawaysCanManage']);
    }

    public function test_guests_cannot_export(): void
    {
        $this->addMembers(2);
        $this->bootWithGiveaways();

        $response = $this->send($this->request('GET', '/api/giveaways/wins/export'));

        $this->assertSame(401, $response->getStatusCode());
    }

    public function test_each_winner_gets_only_their_own_claim_instructions(): void
    {
        $this->addMembers(2);
        $this->bootWithGiveaways();

        $giveaway = $this->createGiveaway([
            'status' => Giveaway::STATUS_DRAWN,
            'winner_count' => 2,
        ], [
            'claim_instructions' => "First winner instructions\nSecond winner instructions",
        ]);

        $this->forceEntry($giveaway->id, 2);
        $this->win($giveaway, 2, 2);

        $response = $this->send($this->request('GET', '/api/giveaways/wins/export', [
            'authenticatedAs' => 2,
        ]));

        $body = (string) $response->getBody();

        $this->assertStringContainsString('Second winner instructions', $body);
        $this->assertStringNotContainsString('First winner instructions', $body);
    }

    /** @return array<string, mixed> */
    protected function forumAttributes(int $userId): array
    {
        $response = $this->send($this->request('GET', '/api', ['authenticatedAs' => $userId]));
        $this->assertSame(200, $response->getStatusCode());

        return json_decode((string) $response->getBody(), true)['data']['attributes'];
    }

    public function test_only_won_giveaways_are_exported(): void
    {
        $this->addMembers(2);
        $this->bootWithGiveaways();

        $lost = $this->createGiveaway(['status' => Giveaway::STATUS_DRAWN, 'prize' => 'Lost prize']);
        $this->forceEntry($lost->id, 2);

        $response = $this->send($this->request('GET', '/api/giveaways/wins/export', [
            'authenticatedAs' => 2,
        ]));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringNotContainsString('Lost prize', (string) $response->getBody());
    }
}
