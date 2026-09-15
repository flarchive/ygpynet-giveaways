<?php

namespace Ygpynet\Giveaways\Api\Controller;

use Carbon\Carbon;
use Ygpynet\Giveaways\Contract\PointsGateway;
use Ygpynet\Giveaways\Giveaway;
use Ygpynet\Giveaways\GiveawayRefund;
use Flarum\Http\RequestUtil;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Database\QueryException;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /api/giveaways/health — admin-only diagnostics.
 *
 * Reports the scheduler heartbeat (so the admin UI can warn when cron isn't
 * configured), how many draws are overdue, whether the points gateway is
 * reachable, and how many refunds are stuck in the retry queue. Registered
 * before /giveaways/{id} so the literal "health" segment isn't swallowed.
 */
class HealthController implements RequestHandlerInterface
{
    public function __construct(
        protected CacheRepository $cache,
        protected PointsGateway $points
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        // The scheduler stores a Carbon under this key (ConsoleServiceProvider);
        // some cache drivers hand it back as a string, so parse like core does.
        $lastRun = $this->cache->get('flarum:schedule:last_run');

        $iso = null;
        if ($lastRun) {
            try {
                $iso = Carbon::parse($lastRun)->format(\DateTimeInterface::ATOM);
            } catch (\Throwable $e) {
                $iso = null;
            }
        }

        $payload = [
            'scheduleLastRun' => $iso,
            'pointsAvailable' => $this->points->available(),
            'schemaOutdated'  => false,
        ];

        // Diagnostics must degrade, never 500: after an extension upgrade the
        // admin screen polls this endpoint BEFORE the operator has run
        // `php flarum migrate`, so brand-new tables/columns may be missing.
        // Report that instead of exploding on top of it.
        $now = Carbon::now();

        try {
            $overdue = Giveaway::query()
                ->where('status', Giveaway::STATUS_ACTIVE)
                ->whereNotNull('ends_at')
                ->where('ends_at', '<=', $now);

            $nextDue = Giveaway::query()
                ->where('status', Giveaway::STATUS_ACTIVE)
                ->whereNotNull('ends_at')
                ->where('ends_at', '>', $now)
                ->min('ends_at');

            $payload['pendingDraws'] = (clone $overdue)->count();
            $payload['oldestDueAt'] = ($f = (clone $overdue)->min('ends_at')) ? Carbon::parse($f)->format(\DateTimeInterface::ATOM) : null;
            $payload['nextDueAt'] = $nextDue ? Carbon::parse($nextDue)->format(\DateTimeInterface::ATOM) : null;
        } catch (QueryException $e) {
            $payload['pendingDraws'] = null;
            $payload['oldestDueAt'] = null;
            $payload['nextDueAt'] = null;
            $payload['schemaOutdated'] = true;
        }

        try {
            $payload['pendingRefunds'] = GiveawayRefund::pending()->count();
        } catch (QueryException $e) {
            $payload['pendingRefunds'] = null;
            $payload['schemaOutdated'] = true;
        }

        return new JsonResponse($payload);
    }
}