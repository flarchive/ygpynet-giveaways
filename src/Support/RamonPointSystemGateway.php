<?php

namespace ErnestDefoe\Giveaways\Support;

use ErnestDefoe\Giveaways\Contract\PointsGateway;
use Flarum\User\User;
use Ramon\PointSystem\Repository\PointsRepository;

/**
 * Default PointsGateway: the soft bridge to ramon/point-system.
 *
 * All ramon/* class touches stay inside Support\PointSystem so this class (and
 * everything depending on the interface) never hard-references an optional
 * dependency.
 */
class RamonPointSystemGateway implements PointsGateway
{
    public function available(): bool
    {
        return PointSystem::available();
    }

    public function balanceOf(User $user): int
    {
        return PointSystem::balanceOf($user);
    }

    public function deduct(User $user, int $amount, string $reason, string $referenceType, int $referenceId): void
    {
        $repo = $this->requireRepository();
        // Re-thrown \DomainException on insufficient balance is part of the
        // interface contract — callers map it to a localized validation error.
        $repo->deduct($user, $amount, $reason, $referenceType, $referenceId);
    }

    public function award(User $user, int $amount, string $reason, string $referenceType, int $referenceId): void
    {
        $this->requireRepository()->award($user, $amount, $reason, $referenceType, $referenceId);
    }

    protected function requireRepository(): PointsRepository
    {
        $repo = PointSystem::repository();
        if (! $repo) {
            throw new \RuntimeException('No points integration is bound (ramon/point-system not installed or disabled).');
        }
        return $repo;
    }
}
