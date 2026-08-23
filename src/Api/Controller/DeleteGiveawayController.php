<?php

namespace ErnestDefoe\Giveaways\Api\Controller;

use ErnestDefoe\Giveaways\Giveaway;
use ErnestDefoe\Giveaways\GiveawayEntry;
use ErnestDefoe\Giveaways\GiveawayWinner;
use Flarum\Http\RequestUtil;
use Flarum\User\Exception\PermissionDeniedException;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\EmptyResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** DELETE /api/giveaways/{id} — remove a giveaway and its entries/winners. */
class DeleteGiveawayController implements RequestHandlerInterface
{
    public function __construct(protected ConnectionInterface $db)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();

        $id = (int) Arr::get($request->getAttributes(), 'routeParameters.id');
        $g = Giveaway::query()->findOrFail($id);

        if (! $g->canBeManagedBy($actor)) {
            throw new PermissionDeniedException();
        }

        // Children also cascade at the DB level (FK constraints), but delete them
        // explicitly too so cleanup works even on a driver without FK enforcement.
        // The whole removal runs in one transaction so a partial failure can
        // never leave orphaned entries/winners behind.
        $this->db->transaction(function () use ($g) {
            GiveawayEntry::where('giveaway_id', $g->id)->delete();
            GiveawayWinner::where('giveaway_id', $g->id)->delete();
            $g->delete();
        });

        return new EmptyResponse(204);
    }
}
