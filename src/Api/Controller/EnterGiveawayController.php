<?php

namespace ErnestDefoe\Giveaways\Api\Controller;

use ErnestDefoe\Giveaways\Api\GiveawayPresenter;
use ErnestDefoe\Giveaways\Contract\PointsGateway;
use ErnestDefoe\Giveaways\Exception\GiveawayClosedException;
use ErnestDefoe\Giveaways\EntryService;
use ErnestDefoe\Giveaways\Giveaway;
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
    public function __construct(
        protected EntryService $entries,
        protected TranslatorInterface $translator,
        protected PointsGateway $points,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();
        $actor->assertCan('giveaways.enter');

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
                'ernestdefoe-giveaways.api.enter_insufficient_points',
                ['cost' => $this->entries->entryCost($g), 'balance' => $this->points->balanceOf($actor)]
            )]);
        }

        return new JsonResponse(['data' => GiveawayPresenter::forActor($actor)->present($g, true)]);
    }
}
