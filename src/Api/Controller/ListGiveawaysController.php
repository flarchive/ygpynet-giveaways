<?php

namespace ErnestDefoe\Giveaways\Api\Controller;

use ErnestDefoe\Giveaways\Api\GiveawayPresenter;
use ErnestDefoe\Giveaways\Giveaway;
use Flarum\Http\RequestUtil;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** GET /api/giveaways — list (active first, then drawn), paginated. */
class ListGiveawaysController implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);

        $page = max(1, (int) Arr::get($request->getQueryParams(), 'page', 1));
        $perPage = 100;

        $query = Giveaway::query()->with(['user', 'category'])
            // Portable ordering — FIELD() is MySQL-only (breaks PG/SQLite).
            ->orderByRaw("CASE status WHEN 'active' THEN 0 WHEN 'drawn' THEN 1 WHEN 'cancelled' THEN 2 ELSE 3 END")
            ->orderBy('ends_at', 'desc');

        $total = (clone $query)->count();

        // Clamp the requested page to the real range so a huge ?page=N can't
        // force the DB into an expensive deep-offset scan.
        $page = max(1, min($page, max(1, (int) ceil($total / $perPage))));

        $giveaways = $query->offset(($page - 1) * $perPage)->limit($perPage)->get();

        // Batch-load per-row aggregates + the actor's own entry/win once (no N+1).
        $presenter = GiveawayPresenter::forList($actor, $giveaways);
        $data = $giveaways->map(fn (Giveaway $g) => $presenter->present($g, false))->all();

        return new JsonResponse([
            'data' => $data,
            'meta' => [
                'canCreate' => $actor->hasPermission('giveaways.create') || $actor->hasPermission('giveaways.manage'),
                'canManage' => $actor->hasPermission('giveaways.manage'),
                'page'      => $page,
                'hasMore'   => $page * $perPage < $total,
                'total'     => $total,
            ],
        ]);
    }
}
