<?php

namespace ErnestDefoe\Giveaways\Api\Controller;

use ErnestDefoe\Giveaways\Api\GiveawayPresenter;
use ErnestDefoe\Giveaways\Giveaway;
use Flarum\Http\RequestUtil;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** GET /api/giveaways/{id} — one giveaway with winners + verification data. */
class ShowGiveawayController implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $key = rawurldecode((string) Arr::get($request->getAttributes(), 'routeParameters.id'));

        $g = Giveaway::query()->with(['user', 'category'])->where('slug', $key)->first();

        // Slugs can legitimately be pure numbers (a title like "123"), so a
        // numeric key is always tried as a slug first, then falls back to a
        // raw id lookup so direct /api/giveaways/{id} calls keep working.
        if (! $g && ctype_digit($key)) {
            $g = Giveaway::query()->with(['user', 'category'])->find((int) $key);
        }

        if (! $g) {
            throw new ModelNotFoundException();
        }

        return new JsonResponse(['data' => GiveawayPresenter::forActor($actor)->present($g, true)]);
    }
}
