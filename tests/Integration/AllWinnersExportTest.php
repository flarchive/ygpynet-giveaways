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
 * The global winners export: managers download one CSV with every winner of
 * every giveaway and the claim instructions each winner received. It contains
 * members' email addresses, so nobody else may download it.
 */
class AllWinnersExportTest extends GiveawayTestCase
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

    public function test_a_manager_exports_every_winner_of_every_giveaway(): void
    {
        $this->addMembers(2, 3);
        $this->bootWithGiveaways();

        $spring = $this->createGiveaway([
            'status' => Giveaway::STATUS_DRAWN,
            'title' => 'Spring raffle',
            'prize' => 'Golden Ticket',
        ], [
            'claim_instructions' => 'DM me your shipping address.',
        ]);
        $spring->drawn_at = Carbon::parse('2026-03-01 12:00:00');
        $spring->save();

        $summer = $this->createGiveaway([
            'status' => Giveaway::STATUS_DRAWN,
            'title' => 'Summer raffle',
            'prize' => 'Beach Umbrella',
        ], [
            'claim_instructions' => 'Email the host.',
        ]);
        $summer->drawn_at = Carbon::parse('2026-06-01 12:00:00');
        $summer->save();

        // Entrants who did not win must not appear.
        $this->forceEntry($summer->id, 3);
        $this->win($spring, 2, 1, '2026-03-02 09:00:00');
        $this->win($summer, 3, 1);

        $response = $this->send($this->request('GET', '/api/giveaways/winners/export', [
            'authenticatedAs' => 1, // admin / global manager
        ]));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('text/csv', $response->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('attachment;', $response->getHeaderLine('Content-Disposition'));

        $body = (string) $response->getBody();

        foreach ([
            'Spring raffle',
            'Golden Ticket',
            'Summer raffle',
            'Beach Umbrella',
            'member2',
            'member3',
            'member2@machine.local',
            'member3@machine.local',
            'DM me your shipping address.',
            'Email the host.',
            '2026-03-01 12:00:00',
            '2026-03-02 09:00:00',
        ] as $expected) {
            $this->assertStringContainsString($expected, $body, "the export must contain {$expected}");
        }
    }

    public function test_each_winner_gets_only_their_own_claim_instructions(): void
    {
        $this->addMembers(2, 3);
        $this->bootWithGiveaways();

        $giveaway = $this->createGiveaway([
            'status' => Giveaway::STATUS_DRAWN,
            'winner_count' => 2,
        ], [
            'claim_instructions' => "First winner instructions\nSecond winner instructions",
        ]);

        $this->win($giveaway, 2, 1);
        $this->win($giveaway, 3, 2);

        $body = (string) $this->send($this->request('GET', '/api/giveaways/winners/export', [
            'authenticatedAs' => 1,
        ]))->getBody();

        $lines = array_values(array_filter(
            preg_split('/\R/', $body) ?: [],
            fn ($line) => trim((string) $line) !== ''
        ));

        $this->assertCount(3, $lines);
        $this->assertStringContainsString('First winner instructions', $lines[1]);
        $this->assertStringNotContainsString('Second winner instructions', $lines[1]);
        $this->assertStringContainsString('Second winner instructions', $lines[2]);
        $this->assertStringNotContainsString('First winner instructions', $lines[2]);
    }

    public function test_only_winners_are_exported(): void
    {
        $this->addMembers(2);
        $this->bootWithGiveaways();

        $giveaway = $this->createGiveaway([
            'status' => Giveaway::STATUS_DRAWN,
            'title' => 'Drawn with no winners',
        ]);
        $this->forceEntry($giveaway->id, 2);

        $body = (string) $this->send($this->request('GET', '/api/giveaways/winners/export', [
            'authenticatedAs' => 1,
        ]))->getBody();

        $this->assertStringNotContainsString('Drawn with no winners', $body);
        $this->assertStringNotContainsString('member2@machine.local', $body);
    }

    public function test_members_cannot_export_the_global_winners_list(): void
    {
        $this->addMembers(2);
        $this->bootWithGiveaways();

        $giveaway = $this->createGiveaway(['status' => Giveaway::STATUS_DRAWN]);
        $this->win($giveaway, 2);

        $response = $this->send($this->request('GET', '/api/giveaways/winners/export', [
            'authenticatedAs' => 2,
        ]));

        $this->assertSame(403, $response->getStatusCode());
    }

    public function test_guests_cannot_export_the_global_winners_list(): void
    {
        $this->addMembers(2);
        $this->bootWithGiveaways();

        $response = $this->send($this->request('GET', '/api/giveaways/winners/export'));

        $this->assertSame(401, $response->getStatusCode());
    }

    public function test_user_authored_cells_are_neutralized_for_spreadsheets(): void
    {
        $this->addMembers(2);
        $this->bootWithGiveaways();

        $giveaway = $this->createGiveaway([
            'status' => Giveaway::STATUS_DRAWN,
            'title' => '=HYPERLINK("https://evil.test")',
        ], [
            'claim_instructions' => '@SUM(1+1)',
        ]);
        $this->win($giveaway, 2);

        $body = (string) $this->send($this->request('GET', '/api/giveaways/winners/export', [
            'authenticatedAs' => 1,
        ]))->getBody();

        $this->assertStringContainsString('\'=HYPERLINK', $body);
        $this->assertStringContainsString('\'@SUM', $body);
    }
}
