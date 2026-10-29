<?php

namespace Ygpynet\Giveaways\Api\Controller;

use Ygpynet\Giveaways\Api\GiveawayPresenter;
use Ygpynet\Giveaways\DrawService;
use Ygpynet\Giveaways\Giveaway;
use Ygpynet\Giveaways\Support\Throttle;
use Flarum\Http\RequestUtil;
use Flarum\Locale\TranslatorInterface;
use Flarum\User\Exception\PermissionDeniedException;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** POST /api/giveaways/{id}/draw — manually run the provably-fair draw now. */
class DrawGiveawayController implements RequestHandlerInterface
{
    protected const RATE_LIMIT = 10;
    protected const RATE_WINDOW = 60;

    public function __construct(
        protected DrawService $draws,
        protected Throttle $throttle,
        protected TranslatorInterface $translator
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();

        if ($this->throttle->tooManyRequests('draw', (int) $actor->id, self::RATE_LIMIT, self::RATE_WINDOW)) {
            return new JsonResponse(['errors' => [[
                'code'   => 'giveaways.rate_limited',
                'detail' => $this->translator->trans('ygpynet-giveaways.api.rate_limited'),
            ]]], 429);
        }

        $id = (int) Arr::get($request->getAttributes(), 'routeParameters.id');
        $g = Giveaway::query()->with(['user', 'category'])->findOrFail($id);

        if (! $g->canBeManagedBy($actor)) {
            throw new PermissionDeniedException();
        }

        $this->draws->draw($g);
        $g->refresh();
        $g->load(['user', 'category']);

        return new JsonResponse(['data' => GiveawayPresenter::forActor($actor)->present($g, true)]);
    }
}
