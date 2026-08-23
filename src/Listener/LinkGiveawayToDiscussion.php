<?php

namespace ErnestDefoe\Giveaways\Listener;

use ErnestDefoe\Giveaways\Giveaway;
use Flarum\Post\Event\Posted;

/**
 * When a discussion's first post is created, scan it for [giveaway slug=...]
 * and link the giveaway to the discussion (activating drafts) so it becomes
 * visible on /giveaways.
 */
class LinkGiveawayToDiscussion
{
    public function handle(Posted $event): void
    {
        $post = $event->post;

        // Only the first post of a discussion can bind giveaways.
        if ((int) $post->number !== 1) {
            return;
        }

        if (! preg_match_all('/\[giveaway slug=([^\s\]]+)/', (string) $post->content, $matches)) {
            return;
        }

        foreach ($matches[1] as $slug) {
            $giveaway = Giveaway::query()->where('slug', $slug)->first();
            if ($giveaway && ! $giveaway->discussion_id) {
                $giveaway->discussion_id = $post->discussion_id;
                if ($giveaway->status === 'draft') {
                    $giveaway->status = 'active';
                }
                $giveaway->save();
            }
        }
    }
}
