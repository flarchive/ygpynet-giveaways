<?php

namespace ErnestDefoe\Giveaways\Contract;

use Flarum\User\User;

/**
 * Abstraction over any points/currency system used to charge giveaway entry
 * fees. The default binding is the soft bridge to ramon/point-system; sites
 * (or other extensions) can swap in their own implementation by rebinding
 * this interface in a service provider — no core code changes needed.
 */
interface PointsGateway
{
    public const REASON_ENTRY = 'giveaway.entry';
    public const REASON_REFUND = 'giveaway.entry.refund';
    public const REFERENCE_TYPE = 'giveaway';

    /** Whether a points system is present and usable right now. */
    public function available(): bool;

    /** Read-only balance; a user without an account counts as 0. */
    public function balanceOf(User $user): int;

    /**
     * Charge $amount to the user.
     *
     * @throws \DomainException when the balance is insufficient
     */
    public function deduct(User $user, int $amount, string $reason, string $referenceType, int $referenceId): void;

    /** Credit $amount back to the user (entry-fee refunds). */
    public function award(User $user, int $amount, string $reason, string $referenceType, int $referenceId): void;
}
