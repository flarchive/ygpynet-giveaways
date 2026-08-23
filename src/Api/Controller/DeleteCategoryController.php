<?php

namespace ErnestDefoe\Giveaways\Api\Controller;

use ErnestDefoe\Giveaways\Giveaway;
use ErnestDefoe\Giveaways\GiveawayCategory;
use Flarum\Http\RequestUtil;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\EmptyResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** DELETE /api/giveaway-categories/{id} — uncategorize its giveaways, then remove it. */
class DeleteCategoryController implements RequestHandlerInterface
{
    public function __construct(protected ConnectionInterface $db)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertCan('giveaways.manage');

        $id = (int) Arr::get($request->getAttributes(), 'routeParameters.id');
        $cat = GiveawayCategory::query()->findOrFail($id);

        $this->db->transaction(function () use ($cat) {
            Giveaway::where('category_id', $cat->id)->update(['category_id' => null]);
            $cat->delete();
        });

        return new EmptyResponse(204);
    }
}
