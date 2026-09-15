<?php

/*
 * This file is part of ygpynet/giveaways.
 *
 * Licensed under the MIT license.
 */

namespace Ygpynet\Giveaways\Tests\Unit\Support\Eligibility;

use Carbon\Carbon;
use Flarum\Locale\Translator;
use Flarum\User\User;
use PHPUnit\Framework\TestCase;
use Ygpynet\Giveaways\Contract\PointsGateway;
use Ygpynet\Giveaways\Giveaway;
use Ygpynet\Giveaways\Support\Eligibility\MinAccountAgeRule;
use Ygpynet\Giveaways\Support\Eligibility\MinPostsRule;
use Ygpynet\Giveaways\Support\Eligibility\PointsBalanceRule;

class EligibilityRulesTest extends TestCase
{
    protected function translator(): Translator
    {
        return new Translator('en');
    }

    protected function giveaway(array $settings): Giveaway
    {
        $g = new Giveaway();
        $g->settings = json_encode($settings);

        return $g;
    }

    /**
     * Flarum's User guards mass assignment, and its datetime casts resolve
     * the date format through a DB connection — neither exists in a unit
     * test. ThrottleTestUser pins the format and writes attributes directly.
     */
    protected function user(array $attributes): User
    {
        $user = new ConnectionlessUser();

        foreach ($attributes as $key => $value) {
            $user->{$key} = $value;
        }

        return $user;
    }

    public function test_min_posts_rule_blocks_and_passes(): void
    {
        $rule = new MinPostsRule($this->translator());

        $this->assertNotNull($rule->check($this->giveaway(['min_posts' => 10]), $this->user(['comment_count' => 3])));
        $this->assertNull($rule->check($this->giveaway(['min_posts' => 10]), $this->user(['comment_count' => 10])));
        $this->assertNull($rule->check($this->giveaway(['min_posts' => 0]), $this->user(['comment_count' => 0])));
    }

    public function test_min_account_age_rule_blocks_recent_accounts(): void
    {
        $rule = new MinAccountAgeRule($this->translator());

        $young = $this->user(['joined_at' => Carbon::now()->subDays(2)]);
        $old = $this->user(['joined_at' => Carbon::now()->subDays(30)]);

        $this->assertNotNull($rule->check($this->giveaway(['min_age_days' => 7]), $young));
        $this->assertNull($rule->check($this->giveaway(['min_age_days' => 7]), $old));
        $this->assertNull($rule->check($this->giveaway(['min_age_days' => 0]), $young));
    }

    public function test_points_rule_is_free_when_cost_is_zero(): void
    {
        $rule = new PointsBalanceRule($this->translator(), new FakePointsGateway(available: true, balance: 0));

        $this->assertNull($rule->check($this->giveaway(['entry_cost_points' => 0]), $this->user([])));
    }

    public function test_points_rule_blocks_when_gateway_unavailable_for_a_paid_giveaway(): void
    {
        $rule = new PointsBalanceRule($this->translator(), new FakePointsGateway(available: false, balance: 999));

        $this->assertNotNull($rule->check($this->giveaway(['entry_cost_points' => 5]), $this->user([])));
    }

    public function test_points_rule_blocks_on_insufficient_balance_and_passes_with_enough(): void
    {
        $poor = new PointsBalanceRule($this->translator(), new FakePointsGateway(available: true, balance: 4));
        $rich = new PointsBalanceRule($this->translator(), new FakePointsGateway(available: true, balance: 5));

        $giveaway = $this->giveaway(['entry_cost_points' => 5]);

        $this->assertNotNull($poor->check($giveaway, $this->user([])));
        $this->assertNull($rich->check($giveaway, $this->user([])));
    }
}

/**
 * Minimal in-memory PointsGateway so the rules can be tested without the
 * optional ramon/point-system dependency.
 */
class FakePointsGateway implements PointsGateway
{
    public function __construct(
        protected bool $available,
        protected int $balance
    ) {
    }

    public function available(): bool
    {
        return $this->available;
    }

    public function balanceOf(User $user): int
    {
        return $this->balance;
    }

    public function deduct(User $user, int $amount, string $reason, string $referenceType, int $referenceId): void
    {
        if ($this->balance < $amount) {
            throw new \DomainException('insufficient balance');
        }
    }

    public function award(User $user, int $amount, string $reason, string $referenceType, int $referenceId): void
    {
    }
}

/**
 * A User whose datetime casts never ask for a DB connection: Eloquent
 * resolves the date format through the connection grammar unless the model
 * pins one itself, and $dateFormat is protected — so a subclass is the
 * clean way to do it in a unit test.
 */
class ConnectionlessUser extends User
{
    public function getDateFormat()
    {
        return 'Y-m-d H:i:s';
    }
}
