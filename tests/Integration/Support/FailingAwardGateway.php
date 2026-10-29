<?php

/*
 * This file is part of ygpynet/giveaways.
 *
 * Licensed under the MIT license.
 */

namespace Ygpynet\Giveaways\Tests\Integration\Support;

use Flarum\User\User;

/**
 * MemoryPointsGateway variant whose awards blow up while $fail is on —
 * simulating a points system that goes down exactly when a refund is due.
 */
class FailingAwardGateway extends MemoryPointsGateway
{
    public static bool $fail = false;

    public function award(User $user, int $amount, string $reason, string $referenceType, int $referenceId): void
    {
        if (self::$fail) {
            throw new \RuntimeException('point system is down');
        }

        parent::award($user, $amount, $reason, $referenceType, $referenceId);
    }
}
