<?php

declare(strict_types=1);

/**
 * Analysis-only stubs for the OPTIONAL ramon/point-system dependency.
 *
 * ygpynet/giveaways integrates with ramon/point-system through a soft bridge
 * (src/Support/PointSystem.php): every call site is guarded with
 * class_exists()/resolve(), so the package is NOT a composer requirement.
 * PHPStan therefore cannot resolve the referenced classes and would report
 * "unknown class" errors. These minimal stubs mirror the public surface we
 * use; they are fed to PHPStan via phpstan.neon `scanFiles` and are never
 * autoloaded at runtime. Keep signatures in sync with the real package.
 */

namespace Ramon\PointSystem\Model;

use Flarum\Database\AbstractModel;

class UserPoints extends AbstractModel
{
    protected $table = 'point_system_user_points';

    /** @var int */
    public $balance;

    /** @var int */
    public $lifetime;
}

namespace Ramon\PointSystem\Model;

use Flarum\Database\AbstractModel;

class PointTransaction extends AbstractModel
{
    protected $table = 'point_system_transactions';
}

namespace Ramon\PointSystem\Repository;

use Flarum\User\User;
use Ramon\PointSystem\Model\PointTransaction;
use Ramon\PointSystem\Model\UserPoints;

class PointsRepository
{
    /**
     * @param array<string, mixed>|null $meta
     */
    public function award(User $user, int $amount, string $reason, ?string $referenceType = null, ?int $referenceId = null, ?array $meta = null): ?PointTransaction
    {
        return null;
    }

    /**
     * @throws \DomainException when the balance is insufficient
     */
    public function deduct(User $user, int $amount, string $reason, ?string $referenceType = null, ?int $referenceId = null): PointTransaction
    {
    }

    public function revert(User $user, string $reason, string $referenceType, int $referenceId): void
    {
    }

    public function getOrCreate(User $user): UserPoints
    {
    }

    public function isEnabled(): bool
    {
        return false;
    }
}
