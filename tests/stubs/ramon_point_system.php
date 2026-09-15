<?php

/*
 * This file is part of ygpynet/giveaways.
 *
 * Licensed under the MIT license.
 *
 * Analysis-only stubs for the OPTIONAL ramon/point-system dependency.
 * The real classes are never required at runtime (Support\PointSystem guards
 * every touch with class_exists()); these signatures exist so PHPStan can
 * resolve the type references in Support\PointSystem and the gateway bridge.
 */

namespace Ramon\PointSystem\Repository;

use Flarum\User\User;

interface PointsRepository
{
    /**
     * @throws \DomainException when the user's balance is insufficient
     */
    public function deduct(User $user, int $amount, string $reason, string $referenceType = '', int $referenceId = 0): void;

    public function award(User $user, int $amount, string $reason, string $referenceType = '', int $referenceId = 0): void;
}

namespace Ramon\PointSystem\Model;

/**
 * @method static \Illuminate\Database\Eloquent\Builder<static> query()
 */
class UserPoints extends \Flarum\Database\AbstractModel
{
}
