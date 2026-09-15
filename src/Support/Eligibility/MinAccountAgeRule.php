<?php

namespace Ygpynet\Giveaways\Support\Eligibility;

use Carbon\Carbon;
use Ygpynet\Giveaways\Contract\EligibilityRule;
use Ygpynet\Giveaways\Giveaway;
use Flarum\Locale\TranslatorInterface;
use Flarum\User\User;

/** Requires the entrant's account to be at least `min_age_days` days old. */
class MinAccountAgeRule implements EligibilityRule
{
    public function __construct(protected TranslatorInterface $translator)
    {
    }

    public function check(Giveaway $giveaway, User $user): ?string
    {
        $days = (int) ($giveaway->settingsArray()['min_age_days'] ?? 0);

        if ($days > 0 && $user->joined_at && $user->joined_at->gt(Carbon::now()->subDays($days))) {
            return $this->translator->trans('ygpynet-giveaways.api.enter_too_new');
        }

        return null;
    }
}
