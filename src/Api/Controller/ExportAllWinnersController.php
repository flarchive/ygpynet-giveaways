<?php

namespace Ygpynet\Giveaways\Api\Controller;

use Carbon\Carbon;
use Ygpynet\Giveaways\Api\GiveawayPresenter;
use Ygpynet\Giveaways\GiveawayWinner;
use Flarum\Http\RequestUtil;
use Flarum\Locale\TranslatorInterface;
use Flarum\User\Exception\PermissionDeniedException;
use Laminas\Diactoros\Response\TextResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /api/giveaways/winners/export — a single CSV of every winner of every
 * giveaway, with the claim instructions each winner was shown.
 *
 * This is an audit / prize-fulfilment report across the whole community, so it
 * contains members' email addresses and is restricted to `giveaways.manage`.
 */
class ExportAllWinnersController implements RequestHandlerInterface
{
    public function __construct(
        protected TranslatorInterface $translator
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();

        if (! $actor->hasPermission('giveaways.manage')) {
            throw new PermissionDeniedException();
        }

        // Sort by giveaway (most recently ended first), then by draw position.
        $winners = GiveawayWinner::query()
            ->with(['giveaway', 'user'])
            ->get()
            ->sort(function (GiveawayWinner $a, GiveawayWinner $b): int {
                return $b->giveaway->ends_at->getTimestamp() <=> $a->giveaway->ends_at->getTimestamp()
                    ?: ($a->giveaway->id <=> $b->giveaway->id)
                    ?: ($a->position <=> $b->position);
            });

        $stream = fopen('php://temp', 'r+');

        fputcsv($stream, [
            $this->trans('export.column_giveaway'),
            $this->trans('export.column_prize'),
            $this->trans('export.column_position'),
            $this->trans('export.column_user_id'),
            $this->trans('export.column_username'),
            $this->trans('export.column_display_name'),
            $this->trans('export.column_email'),
            $this->trans('export.column_drawn_at'),
            $this->trans('export.column_claimed_at'),
            $this->trans('export.column_claim_instructions'),
        ]);

        foreach ($winners as $win) {
            $giveaway = $win->giveaway;
            $user = $win->user;

            $settings = $giveaway->settingsArray();
            $instructions = GiveawayPresenter::instructionsForPosition(
                (string) ($settings['claim_instructions'] ?? ''),
                (int) $giveaway->winner_count,
                (int) $win->position
            );

            fputcsv($stream, [
                $this->cell((string) $giveaway->title),
                $this->cell((string) $giveaway->prize),
                $this->cell((string) $win->position),
                $this->cell((string) $user->id),
                $this->cell((string) $user->username),
                $this->cell((string) $user->display_name),
                $this->cell((string) $user->email),
                $this->cell($giveaway->drawn_at?->format('Y-m-d H:i:s') ?? ''),
                $this->cell($win->claimed_at?->format('Y-m-d H:i:s') ?? ''),
                $this->cell($instructions),
            ]);
        }

        rewind($stream);
        $csv = (string) stream_get_contents($stream);
        fclose($stream);

        $filename = sprintf('giveaway-winners-all-%s.csv', Carbon::now()->format('Ymd-His'));

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
     * Titles, prizes and claim instructions are user-authored, so neutralize
     * them before they end up in an admin's Excel.
     */
    protected function cell(string $value): string
    {
        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $value;
        }

        return $value;
    }
}
