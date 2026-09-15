<?php

/*
 * This file is part of ygpynet/giveaways.
 *
 * Licensed under the MIT license.
 */

use Ygpynet\Giveaways\Api\Controller;
use Ygpynet\Giveaways\Console\DrawDueCommand;
use Ygpynet\Giveaways\Giveaway;
use Ygpynet\Giveaways\Listener\AwardPostBonus;
use Ygpynet\Giveaways\Api\Resource;
use Ygpynet\Giveaways\Notification\GiveawayClaimedBlueprint;
use Ygpynet\Giveaways\Notification\GiveawayWonBlueprint;
use Flarum\Extend;
use Flarum\Post\Event\Posted;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Flarum\Api\Resource\DiscussionResource;
use Flarum\Api\Endpoint\Endpoint;
use Flarum\Api\Endpoint\Index;
use Flarum\Api\Schema;
use Flarum\Discussion\Discussion;
use Flarum\Locale\TranslatorInterface;
use Tobyz\JsonApiServer\Schema\Field\Field;
use Ygpynet\Giveaways\Support\GiveawaySlugLookup;
use Ygpynet\Giveaways\Listener\LinkGiveawayToDiscussion;
use Flarum\Discussion\Event\Created;

return [
    (new Extend\Frontend('forum'))
        ->js(__DIR__ . '/js/dist/forum.js')
        ->css(__DIR__ . '/less/forum.less')
        ->route('/giveaways', 'giveaways.index')
        ->route('/giveaways/{slug}', 'giveaways.show'),

    (new Extend\Frontend('admin'))
        ->js(__DIR__ . '/js/dist/admin.js')
        ->css(__DIR__ . '/less/admin.less'),

    new Extend\Locales(__DIR__ . '/locale'),

    (new Extend\ServiceProvider())
        ->register(\Ygpynet\Giveaways\Providers\GiveawaysServiceProvider::class),

     (new Extend\Formatter())
        ->configure(\Ygpynet\Giveaways\Formatter\GiveawayCardConfigure::class)
        ->render(\Ygpynet\Giveaways\Formatter\GiveawayCardRender::class),

    (new Extend\Settings())
        ->serializeToForum('giveawaysNavLabel', 'ygpynet-giveaways.nav_label')
        ->default('ygpynet-giveaways.show_nav', true)
        ->serializeToForum('giveawaysShowNav', 'ygpynet-giveaways.show_nav', 'boolval'),

    (new Extend\Routes('api'))
        ->get('/giveaways/health', 'giveaways.health', Controller\HealthController::class)
        ->get('/giveaways', 'giveaways.index', Controller\ListGiveawaysController::class)
        ->get('/giveaways/{id}', 'giveaways.show', Controller\ShowGiveawayController::class)
        ->get('/giveaways/{id}/entries', 'giveaways.entries.index', Controller\ListEntriesController::class)
        ->post('/giveaways', 'giveaways.create', Controller\SaveGiveawayController::class)
        ->patch('/giveaways/{id}', 'giveaways.update', Controller\SaveGiveawayController::class)
        ->delete('/giveaways/{id}', 'giveaways.delete', Controller\DeleteGiveawayController::class)
        ->post('/giveaways/{id}/enter', 'giveaways.enter', Controller\EnterGiveawayController::class)
        ->post('/giveaways/{id}/draw', 'giveaways.draw', Controller\DrawGiveawayController::class)
        ->post('/giveaways/{id}/claim', 'giveaways.claim', Controller\ClaimGiveawayController::class)
        ->post('/giveaways/{id}/cancel', 'giveaways.cancel', Controller\CancelGiveawayController::class)
        ->get('/giveaway-categories', 'giveaways.categories.index', Controller\ListCategoriesController::class)
        ->post('/giveaway-categories', 'giveaways.categories.create', Controller\SaveCategoryController::class)
        ->patch('/giveaway-categories/{id}', 'giveaways.categories.update', Controller\SaveCategoryController::class)
        ->delete('/giveaway-categories/{id}', 'giveaways.categories.delete', Controller\DeleteCategoryController::class),

    (new Extend\Event())
        ->listen(Posted::class, AwardPostBonus::class)
        ->listen(Posted::class, LinkGiveawayToDiscussion::class),

    (new Extend\ApiResource(Resource\GiveawayResource::class)),

    (new Extend\Notification())
        ->type(GiveawayWonBlueprint::class, ['alert'])
        ->type(GiveawayClaimedBlueprint::class, ['alert']),

    (new Extend\Console())
        ->command(DrawDueCommand::class)
        ->command(\Ygpynet\Giveaways\Console\RetryRefundsCommand::class)
        ->command(\Ygpynet\Giveaways\Console\VerifyCommand::class)
        ->schedule('giveaways:draw-due', function (ScheduledEvent $event) {
            $event->everyMinute()->withoutOverlapping();
        })
        ->schedule('giveaways:retry-refunds', function (ScheduledEvent $event) {
            $event->everyFifteenMinutes()->withoutOverlapping();
        }),
    
    (new Extend\ApiResource(DiscussionResource::class))
    ->endpoint(Index::class, function (Endpoint $endpoint) {
        return $endpoint->eagerLoad('firstPost');
    })
    ->fields(function () {
        return [
            Schema\Boolean::make('hasGiveaway')
                ->get(function (Discussion $discussion) {
                    return (bool) preg_match('/\[giveaway slug=/', (string) ($discussion->firstPost->content ?? ''));
                }),
        ];
    })
    ->field('title', function (Field $field) {
        return $field->get(function (Discussion $discussion) {
            $title = (string) $discussion->title;
            $content = (string) ($discussion->firstPost->content ?? '');

            // Only the first [giveaway slug=...] in the first post counts,
            // mirroring how the giveaway badge (hasGiveaway) works.
            if (! preg_match('/\[giveaway slug=([^\s\]]+)/', $content, $matches)) {
                return $title;
            }

            // Memoized per request — one query per distinct slug per page, not
            // one per discussion row (see GiveawaySlugLookup).
            $giveaway = GiveawaySlugLookup::find($matches[1]);

            if (! $giveaway || ! $giveaway->hasEnded()) {
                return $title;
            }

            $badge = resolve(TranslatorInterface::class)->trans('ygpynet-giveaways.forum.ended_title_prefix');

            return $badge . ' ' . $title;
        });
    }),
];
