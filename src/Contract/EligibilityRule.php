<?php

namespace Ygpynet\Giveaways\Contract;

use Ygpynet\Giveaways\Giveaway;
use Flarum\User\User;

/**
 * A single participant-eligibility gate, evaluated before entry is created
 * (and before any points are charged).
 *
 * Default bindings: minimum posts, minimum account age, points balance
 * (see Support\Eligibility\*). EntryService evaluates the *structural* gates
 * itself (login, running window) and delegates every rule-based gate to the
 * tagged set of rules, so third-party extensions can add requirements
 * without touching this extension:
 *
 *     $container->tag(MyGate::class, EligibilityRule::class);
 *     $container->singleton(MyGate::class, MyGate::class); // or its own impl
 *
 * Rules are evaluated in registration order; the first non-null reason wins
 * and is returned to the client as a localized validation error.
 */
interface EligibilityRule
{
    /**
     * @return string|null a localized, user-facing reason the actor may not
     *                     enter this giveaway, or null when the rule passes
     */
    public function check(Giveaway $giveaway, User $user): ?string;
}
