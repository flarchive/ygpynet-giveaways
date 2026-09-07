<?php

namespace ErnestDefoe\Giveaways\Api\Controller;

use ErnestDefoe\Giveaways\Api\GiveawayPresenter;
use ErnestDefoe\Giveaways\CancelService;
use ErnestDefoe\Giveaways\Giveaway;
use Flarum\Foundation\ValidationException;
use Flarum\Http\RequestUtil;
use Flarum\Locale\TranslatorInterface;
use Flarum\User\Exception\PermissionDeniedException;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST /api/giveaways/{id}/cancel — draft/active → cancelled, refunding paid
 * entry fees. drawn/cancelled are terminal and reject with a localized error.
 */
class CancelGiveawayController implements RequestHandlerInterface
{
    public function __construct(
        protected CancelService $cancels,
        protected TranslatorInterface $translator
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();

        $id = (int) Arr::get($request->getAttributes(), 'routeParameters.id');
        $g = Giveaway::query()->with(['user', 'category'])->findOrFail($id);

        if (! $g->canBeManagedBy($actor)) {
            throw new PermissionDeniedException();
        }

        try {
            $this->cancels->cancel($g);
        } catch (\DomainException $e) {
            // Lost a race against a concurrent draw, or already terminal.
            throw new ValidationException([
                'cancel' => $this->translator->trans('ernestdefoe-giveaways.api.cancel_not_allowed'),
            ]);
        }

        $g->refresh();
        $g->load(['user', 'category']);

        return new JsonResponse(['data' => GiveawayPresenter::forActor($actor)->present($g, true)]);
    }
}
