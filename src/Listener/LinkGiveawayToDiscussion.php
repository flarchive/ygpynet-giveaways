<?php

namespace ErnestDefoe\Giveaways\Listener;

use ErnestDefoe\Giveaways\Giveaway;
use Flarum\Discussion\Event\Created;

/**
 * When a discussion is published, scan its first post for [giveaway slug=...]
 * and link the giveaway to the discussion so it becomes visible on /giveaways.
 */
class LinkGiveawayToDiscussion
{
    public function handle(Created $event): void
    {
        $discussion = $event->discussion;
        $discussion->load('firstPost');

        $post = $discussion->firstPost ?? null;
        if (! $post || empty($post->content)) {
            return;
        }

        if (preg_match_all('/\[giveaway slug=([^\s\]]+)/', $post->content, $matches)) {
            foreach ($matches[1] as $slug) {
                $giveaway = Giveaway::query()->where('slug', $slug)->first();
                if ($giveaway && ! $giveaway->discussion_id) {
                    $giveaway->discussion_id = $event->discussion->id;
                    $giveaway->save();
                }
            }
        }
    }
}
