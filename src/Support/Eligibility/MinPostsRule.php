<?php

namespace Ygpynet\Giveaways\Support\Eligibility;

use Ygpynet\Giveaways\Contract\EligibilityRule;
use Ygpynet\Giveaways\Giveaway;
use Flarum\Locale\TranslatorInterface;
use Flarum\User\User;

/** Requires the entrant to have at least `min_posts` posts. */
class MinPostsRule implements EligibilityRule
{
    public function __construct(protected TranslatorInterface $translator)
    {
    }

    public function check(Giveaway $giveaway, User $user): ?string
    {
        $min = (int) ($giveaway->settingsArray()['min_posts'] ?? 0);

        if ($min > 0 && (int) $user->comment_count < $min) {
            return $this->translator->trans('ygpynet-giveaways.api.enter_min_posts', ['count' => $min]);
        }

        return null;
    }
}
