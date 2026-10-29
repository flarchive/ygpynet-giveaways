<?php

namespace Ygpynet\Giveaways\Api\Controller;

use Ygpynet\Giveaways\Api\GiveawayPresenter;
use Ygpynet\Giveaways\Contract\PointsGateway;
use Ygpynet\Giveaways\Exception\GiveawayClosedException;
use Ygpynet\Giveaways\Support\Throttle;
use Ygpynet\Giveaways\EntryService;
use Ygpynet\Giveaways\Giveaway;
use Flarum\Foundation\ValidationException;
use Flarum\Http\RequestUtil;
use Flarum\Locale\TranslatorInterface;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** POST /api/giveaways/{id}/enter — register the actor's base entry. */
class EnterGiveawayController implements RequestHandlerInterface
{
    /** Anti-abuse ceiling for scripted entry attempts, per actor. */
    protected const RATE_LIMIT = 30;
    protected const RATE_WINDOW = 60;

    public function __construct(
        protected EntryService $entries,
        protected TranslatorInterface $translator,
        protected PointsGateway $points,
        protected Throttle $throttle
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();
        $actor->assertCan('giveaways.enter');

        if ($this->throttle->tooManyRequests('enter', (int) $actor->id, self::RATE_LIMIT, self::RATE_WINDOW)) {
            return new JsonResponse(['errors' => [[
                'code'   => 'giveaways.rate_limited',
                'detail' => $this->translator->trans('ygpynet-giveaways.api.rate_limited'),
            ]]], 429);
        }

        $id = (int) Arr::get($request->getAttributes(), 'routeParameters.id');
        $g = Giveaway::query()->with(['user', 'category'])->findOrFail($id);

        $reason = $this->entries->ineligibleReason($g, $actor);
        if ($reason) {
            throw new ValidationException(['enter' => $reason]);
        }

        try {
            $this->entries->enter($g, $actor);
        } catch (GiveawayClosedException $e) {
            // The locked re-check inside enter() found the giveaway closed
            // (draw raced us, or the window ended between check and write).
            throw new ValidationException(['enter' => $this->translator->trans($e->reasonKey)]);
        } catch (\DomainException $e) {
            // Race fallback: the balance dropped between the eligibility check
            // and the atomic charge (e.g. a concurrent spend on another tab).
            throw new ValidationException(['enter' => $this->translator->trans(
                'ygpynet-giveaways.api.enter_insufficient_points',
                ['cost' => $this->entries->entryCost($g), 'balance' => $this->points->balanceOf($actor)]
            )]);
        }

        return new JsonResponse(['data' => GiveawayPresenter::forActor($actor)->present($g, true)]);
    }
}
