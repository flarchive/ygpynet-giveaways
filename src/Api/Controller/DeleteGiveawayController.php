<?php

namespace Ygpynet\Giveaways\Api\Controller;

use Ygpynet\Giveaways\Giveaway;
use Ygpynet\Giveaways\GiveawayEntry;
use Ygpynet\Giveaways\GiveawayWinner;
use Ygpynet\Giveaways\RefundService;
use Ygpynet\Giveaways\Support\GiveawaySlugLookup;
use Flarum\Http\RequestUtil;
use Flarum\User\Exception\PermissionDeniedException;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\EmptyResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * DELETE /api/giveaways/{id} — remove a giveaway and its entries/winners.
 *
 * A live (draft/active) giveaway is taken down as `cancelled` FIRST, inside
 * the same transaction that deletes the rows: the atomic claim blocks a
 * concurrent enter()/draw() mid-teardown, and the entrants' recorded
 * payments are captured as evidence before the rows vanish. Refunds then run
 * AFTER the commit — money must never leave the points system for a deletion
 * that could still roll back — and a failed refund follows the normal
 * giveaway_refunds queue path, so deleting a paid giveaway can no longer
 * strand entrant points.
 */
class DeleteGiveawayController implements RequestHandlerInterface
{
    public function __construct(
        protected ConnectionInterface $db,
        protected RefundService $refunds
    ) {
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

        $owed = [];

        // Children also cascade at the DB level (FK constraints), but delete them
        // explicitly too so cleanup works even on a driver without FK enforcement.
        // The whole removal runs in one transaction so a partial failure can
        // never leave orphaned entries/winners behind.
        $this->db->transaction(function () use ($g, &$owed) {
            // draft/active → cancelled atomically; if another worker already
            // moved it to a terminal status the claim simply fails and the
            // rows are deleted as-is (no money is owed for drawn/cancelled).
            if ($g->claimStatus([Giveaway::STATUS_DRAFT, Giveaway::STATUS_ACTIVE], Giveaway::STATUS_CANCELLED)) {
                $owed = GiveawayEntry::query()
                    ->where('giveaway_id', $g->id)
                    ->where('paid_amount', '>', 0)
                    ->get(['user_id', 'paid_amount'])
                    ->all();
            }

            GiveawayEntry::where('giveaway_id', $g->id)->delete();
            GiveawayWinner::where('giveaway_id', $g->id)->delete();
            $g->delete();
        });

        // Post-commit refunds (RefundService never throws; failures queue).
        foreach ($owed as $row) {
            $user = User::query()->find((int) $row->user_id);
            if ($user) {
                $this->refunds->refund($g, $user, (int) $row->paid_amount);
            }
        }

        GiveawaySlugLookup::flush();

        return new EmptyResponse(204);
    }
}
