<?php

namespace Ygpynet\Giveaways\Support\Eligibility;

use Ygpynet\Giveaways\Contract\EligibilityRule;
use Ygpynet\Giveaways\Contract\PointsGateway;
use Ygpynet\Giveaways\Giveaway;
use Flarum\Locale\TranslatorInterface;
use Flarum\User\User;

/**
 * For paid giveaways: the points system must be usable and the entrant's
 * balance must cover the fee. A last-line convenience — the authoritative
 * charge inside EntryService::enter() still guards against a balance race
 * and maps DomainException to the same localized message.
 */
class PointsBalanceRule implements EligibilityRule
{
    public function __construct(
        protected TranslatorInterface $translator,
        protected PointsGateway $points
    ) {
    }

    public function check(Giveaway $giveaway, User $user): ?string
    {
        $cost = (int) ($giveaway->settingsArray()['entry_cost_points'] ?? 0);

        if ($cost <= 0) {
            return null;
        }

        if (! $this->points->available()) {
            return $this->translator->trans('ygpynet-giveaways.api.enter_points_unavailable');
        }

        $balance = $this->points->balanceOf($user);
        if ($balance < $cost) {
            return $this->translator->trans('ygpynet-giveaways.api.enter_insufficient_points', [
                'cost'    => $cost,
                'balance' => $balance,
            ]);
        }

        return null;
    }
}
