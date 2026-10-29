<?php

namespace Ygpynet\Giveaways\Api\Controller;

use Carbon\Carbon;
use Ygpynet\Giveaways\Api\GiveawayPresenter;
use Ygpynet\Giveaways\Giveaway;
use Flarum\Http\RequestUtil;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /api/giveaways — list (active first, then drawn), paginated.
 *
 * Supports server-side filtering so pagination and filters agree (the old
 * client-side filter only saw the loaded page):
 *   filter[status]   = open | past | draft (absent = all)
 *   filter[category] = category id
 */
class ListGiveawaysController implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $params = $request->getQueryParams();

        $page = max(1, (int) Arr::get($params, 'page', 1));
        $perPage = 100;

        $query = Giveaway::query()->with(['user', 'category'])
            // Portable ordering — FIELD() is MySQL-only (breaks PG/SQLite).
            // Drafts sort first so their author sees them at the top of the
            // list; other actors never receive drafts (filter below), so the
            // rank is invisible to them.
            ->orderByRaw("CASE status WHEN 'draft' THEN 0 WHEN 'active' THEN 1 WHEN 'drawn' THEN 2 WHEN 'cancelled' THEN 3 ELSE 4 END")
            ->orderBy('ends_at', 'desc');

        $status = Arr::get($params, 'filter.status');
        if ($status === 'open' || $status === 'ended') {
            $now = Carbon::now();
            if ($status === 'open') {
                $query->where('status', Giveaway::STATUS_ACTIVE)
                    ->where('ends_at', '>', $now);
            } else {
                $query->where(function ($q) use ($now) {
                    $q->whereIn('status', [Giveaway::STATUS_DRAWN, Giveaway::STATUS_CANCELLED])
                        ->orWhere(fn ($q2) => $q2
                            ->where('status', Giveaway::STATUS_ACTIVE)
                            ->where('ends_at', '<=', $now));
                });
            }
        } elseif ($status === 'draft') {
            $query->where('status', Giveaway::STATUS_DRAFT);
        }

        if ($categoryId = (int) Arr::get($params, 'filter.category')) {
            $query->where('category_id', $categoryId);
        }

        // Drafts are private: visible only to their author and global managers.
        // Everyone else gets non-draft giveaways only.
        if (! $actor->hasPermission('giveaways.manage')) {
            if ($actor->isGuest()) {
                $query->where('status', '!=', Giveaway::STATUS_DRAFT);
            } else {
                $query->where(function ($q) use ($actor) {
                    $q->where('status', '!=', Giveaway::STATUS_DRAFT)
                        ->orWhere(fn ($q2) => $q2
                            ->where('status', Giveaway::STATUS_DRAFT)
                            ->where('user_id', $actor->id));
                });
            }
        }

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
