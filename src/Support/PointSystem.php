<?php

namespace ErnestDefoe\Giveaways\Support;

use Flarum\User\User;
use Ramon\PointSystem\Model\UserPoints;
use Ramon\PointSystem\Repository\PointsRepository;

/**
 * Soft bridge to ramon/point-system.
 *
 * The giveaway extension works standalone; this helper is the ONLY place that
 * touches the point system's classes. Everything is guarded with
 * class_exists()/resolve() so a site without the extension installed (or with
 * it disabled) never fatal-errors — callers get null/0 and treat it as "no
 * points integration".
 *
 * Transactions written here use reason 'giveaway.entry' / 'giveaway.entry.refund'
 * with reference_type 'giveaway' + the giveaway id, so admins can audit them in
 * the point system's transaction log.
 */
class PointSystem
{
    public const REASON_ENTRY = 'giveaway.entry';
    public const REASON_REFUND = 'giveaway.entry.refund';
    public const REFERENCE_TYPE = 'giveaway';

    /** Whether the point system extension is present and resolvable. */
    public static function available(): bool
    {
        return self::repository() !== null;
    }

    /** The shared PointsRepository singleton, or null when not installed. */
    public static function repository(): ?PointsRepository
    {
        if (! class_exists(PointsRepository::class)) {
            return null;
        }
        try {
            $repo = resolve(PointsRepository::class);
        } catch (\Throwable) {
            return null;
        }
        return $repo instanceof PointsRepository ? $repo : null;
    }

    /** Read-only balance lookup. A user without a points row counts as 0. */
    public static function balanceOf(User $user): int
    {
        if (! class_exists(UserPoints::class)) {
            return 0;
        }
        return (int) (UserPoints::query()->where('user_id', $user->id)->value('balance') ?? 0);
    }
}
