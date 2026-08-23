<?php

namespace ErnestDefoe\Giveaways\Listener;

use ErnestDefoe\Giveaways\Giveaway;
use Flarum\Post\Event\Posted;
use Psr\Log\LoggerInterface;

/**
 * When a discussion's first post is created, scan it for [giveaway slug=...]
 * and link the giveaway to the discussion (activating drafts) so it becomes
 * visible on /giveaways.
 *
 * Authorization matters here: slugs are not secrets, so WITHOUT a permission
 * gate any member could bind someone else's giveaway into their own thread —
 * or force-publish a draft they don't own by referencing its slug. Only the
 * host (or a global manager) may link, mirroring canBeManagedBy().
 */
class LinkGiveawayToDiscussion
{
    public function __construct(protected LoggerInterface $log)
    {
    }

    public function handle(Posted $event): void
    {
        $post = $event->post;
        $actor = $event->actor;

        if (! $actor || $actor->isGuest()) {
            return;
        }

        // Only the first post of a discussion can bind giveaways.
        if ((int) $post->number !== 1) {
            return;
        }

        if (! preg_match_all('/\[giveaway slug=([^\s\]]+)/', (string) $post->content, $matches)) {
            return;
        }

        try {
            foreach ($matches[1] as $slug) {
                $giveaway = Giveaway::query()->where('slug', $slug)->first();

                // Never let a non-manager bind — and never rebind a giveaway
                // that is already attached to a discussion.
                if (! $giveaway || $giveaway->discussion_id || ! $giveaway->canBeManagedBy($actor)) {
                    continue;
                }

                $giveaway->discussion_id = $post->discussion_id;
                if ($giveaway->status === 'draft') {
                    $giveaway->status = 'active';
                }
                $giveaway->save();
            }
        } catch (\Throwable $e) {
            // The post itself is already persisted when Posted fires; a failed
            // link must not turn the author's reply into a 500.
            $this->log->warning('[giveaways] link-to-discussion failed for post '
                . $post->id . ': ' . $e->getMessage());
        }
    }
}
