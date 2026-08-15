<?php

namespace ErnestDefoe\Giveaways\Api\Controller;

use Carbon\Carbon;
use Flarum\Http\RequestUtil;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /api/giveaways/health — admin-only diagnostics.
 *
 * Currently reports when the scheduler last ran, so the admin UI can warn that
 * automatic draws may be stalled if cron isn't configured. Registered before
 * /giveaways/{id} so the literal "health" segment isn't swallowed by the slug.
 */
class HealthController implements RequestHandlerInterface
{
    public function __construct(protected CacheRepository $cache)
    {
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

        return new JsonResponse([
            'scheduleLastRun' => $iso,
        ]);
    }
}