<?php

namespace ErnestDefoe\Giveaways\Api\Controller;

use ErnestDefoe\Giveaways\Giveaway;
use ErnestDefoe\Giveaways\GiveawayEntry;
use Flarum\Http\RequestUtil;
use Flarum\User\Exception\PermissionDeniedException;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** GET /api/giveaways/{id}/entries — paginated list of who entered. */
class ListEntriesController implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);

        $id = (int) Arr::get($request->getAttributes(), 'routeParameters.id');
        $g = Giveaway::query()->findOrFail($id);

        // Permission: managers/owners can always view; everyone else needs
        // the giveaways.viewEntries permission (default: members).
        if (! $g->canBeManagedBy($actor) && ! $actor->hasPermission('giveaways.viewEntries')) {
            throw new PermissionDeniedException();
        }

        $page = max(1, (int) Arr::get($request->getQueryParams(), 'page', 1));
        $perPage = min(50, max(1, (int) Arr::get($request->getQueryParams(), 'pageSize', 20)));

        $query = GiveawayEntry::query()
            ->where('giveaway_id', $g->id)
            ->with('user')
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        $total = (clone $query)->count();
        $items = $query->forPage($page, $perPage)->get();

        return new JsonResponse([
            'data' => $items->map(fn (GiveawayEntry $e) => [
                'user'      => $e->user ? $this->user($e->user) : null,
                'entries'   => (int) $e->entries,
                'sources'   => $e->sourcesArray() ?: null,
                'createdAt' => optional($e->created_at)->toIso8601String(),
            ])->all(),
            'meta' => [
                'total'   => $total,
                'page'    => $page,
                'hasMore' => $page * $perPage < $total,
            ],
        ]);
    }

    private function user(\Flarum\User\User $u): array
    {
        return [
            'id'          => (int) $u->id,
            'username'    => $u->username,
            'displayName' => $u->display_name,
            'avatarUrl'   => $u->avatar_url,
        ];
    }
}
