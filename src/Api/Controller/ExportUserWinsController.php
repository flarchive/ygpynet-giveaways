<?php

namespace Ygpynet\Giveaways\Api\Controller;

use Carbon\Carbon;
use Ygpynet\Giveaways\Api\GiveawayPresenter;
use Ygpynet\Giveaways\GiveawayEntry;
use Ygpynet\Giveaways\GiveawayWinner;
use Flarum\Http\RequestUtil;
use Flarum\Locale\TranslatorInterface;
use Flarum\User\Exception\PermissionDeniedException;
use Flarum\User\User;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\TextResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /api/giveaways/wins/export?userId={id} — CSV of every giveaway the user
 * has won, with the contact details a host needs to hand out the prizes.
 *
 * Members may export their own record. Exporting somebody else's contains
 * their email address, so it requires `giveaways.manage` (the viewWinners
 * grant is deliberately not enough).
 */
class ExportUserWinsController implements RequestHandlerInterface
{
    public function __construct(
        protected TranslatorInterface $translator
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();

        $params = $request->getQueryParams();
        $userId = (int) Arr::get($params, 'userId', $actor->id);
        if ($userId <= 0) {
            $userId = (int) $actor->id;
        }

        if ($userId !== (int) $actor->id && ! $actor->hasPermission('giveaways.manage')) {
            throw new PermissionDeniedException();
        }

        $user = User::query()->findOrFail($userId);

        // Winners are only ever created by a draw, so filtering on the winner
        // table (not on entries) is exactly "giveaways this user won".
        $wins = GiveawayWinner::query()
            ->where('user_id', $user->id)
            ->with(['giveaway'])
            ->get()
            ->sortByDesc(fn (GiveawayWinner $w) => $w->giveaway->ends_at->getTimestamp())
            ->values();

        // Participation time lives on the entry row; load them all in one
        // query instead of one lookup per win.
        $entries = GiveawayEntry::query()
            ->where('user_id', $user->id)
            ->whereIn('giveaway_id', $wins->pluck('giveaway_id')->all())
            ->get()
            ->keyBy('giveaway_id');

        $stream = fopen('php://temp', 'r+');

        fputcsv($stream, [
            $this->trans('export.column_user_id'),
            $this->trans('export.column_username'),
            $this->trans('export.column_display_name'),
            $this->trans('export.column_email'),
            $this->trans('export.column_giveaway'),
            $this->trans('export.column_prize'),
            $this->trans('export.column_entered_at'),
            $this->trans('export.column_claimed_at'),
            $this->trans('export.column_claim_instructions'),
        ]);

        foreach ($wins as $win) {
            $giveaway = $win->giveaway;
            $entry = $entries->get($win->giveaway_id);

            $settings = $giveaway->settingsArray();
            $instructions = GiveawayPresenter::instructionsForPosition(
                (string) ($settings['claim_instructions'] ?? ''),
                (int) $giveaway->winner_count,
                (int) $win->position
            );

            fputcsv($stream, [
                $this->cell((string) $user->id),
                $this->cell((string) $user->username),
                $this->cell((string) $user->display_name),
                $this->cell((string) $user->email),
                $this->cell((string) $giveaway->title),
                $this->cell((string) $giveaway->prize),
                $this->cell($entry?->created_at?->format('Y-m-d H:i:s') ?? ''),
                $this->cell($win->claimed_at?->format('Y-m-d H:i:s') ?? ''),
                $this->cell($instructions),
            ]);
        }

        rewind($stream);
        $csv = (string) stream_get_contents($stream);
        fclose($stream);

        $filename = sprintf(
            'giveaway-wins-%s-%s.csv',
            preg_replace('/[^A-Za-z0-9_-]/', '', (string) $user->username) ?: 'user',
            Carbon::now()->format('Ymd-His')
        );

        return new TextResponse(
            // BOM so Excel opens the (possibly non-ASCII) columns correctly.
            "\xEF\xBB\xBF" . $csv,
            200,
            [
                'Content-Type'        => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
                'Cache-Control'       => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ]
        );
    }

    protected function trans(string $key): string
    {
        return (string) $this->translator->trans('ygpynet-giveaways.' . $key);
    }

    /**
     * Spreadsheet applications execute cells that start with =, +, - or @.
     * Usernames, display names and claim instructions are user-authored, so
     * neutralize them before they end up in an admin's Excel.
     */
    protected function cell(string $value): string
    {
        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $value;
        }

        return $value;
    }
}
