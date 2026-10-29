<?php

/*
 * This file is part of ygpynet/giveaways.
 *
 * Licensed under the MIT license.
 */

namespace Ygpynet\Giveaways\Tests\Integration\Support;

use Flarum\User\User;
use Ygpynet\Giveaways\Contract\PointsGateway;

/**
 * Deterministic in-memory PointsGateway for integration tests. Balances are
 * static so a test can seed a user's balance, run a paid flow and assert the
 * ledger — with no ramon/point-system and no DB for the point store.
 */
class MemoryPointsGateway implements PointsGateway
{
    /** @var array<int, int> */
    public static array $balances = [];

    /** @var list<array{op:string,user:int,amount:int,reason:string,giveaway:int}> */
    public static array $ledger = [];

    public static int $defaultBalance = 100;

    public static function reset(): void
    {
        self::$balances = [];
        self::$ledger = [];
        self::$defaultBalance = 100;
    }

    public static function seed(int $userId, int $balance): void
    {
        self::$balances[$userId] = $balance;
    }

    public function available(): bool
    {
        return true;
    }

    public function balanceOf(User $user): int
    {
        return self::$balances[$user->id] ?? self::$defaultBalance;
    }

    public function deduct(User $user, int $amount, string $reason, string $referenceType, int $referenceId): void
    {
        $balance = $this->balanceOf($user);

        if ($balance < $amount) {
            throw new \DomainException('insufficient balance');
        }

        self::$balances[$user->id] = $balance - $amount;
        self::$ledger[] = ['op' => 'deduct', 'user' => $user->id, 'amount' => $amount, 'reason' => $reason, 'giveaway' => $referenceId];
    }

    public function award(User $user, int $amount, string $reason, string $referenceType, int $referenceId): void
    {
        self::$balances[$user->id] = $this->balanceOf($user) + $amount;
        self::$ledger[] = ['op' => 'award', 'user' => $user->id, 'amount' => $amount, 'reason' => $reason, 'giveaway' => $referenceId];
    }

    /** @return list<array{op:string,user:int,amount:int,reason:string,giveaway:int}> */
    public static function ledger(string $op): array
    {
        return array_values(array_filter(self::$ledger, fn ($row) => $row['op'] === $op));
    }

    /** Static balance read for test assertions (mirrors balanceOf()). */
    public static function balance(int $userId): int
    {
        return self::$balances[$userId] ?? self::$defaultBalance;
    }
}
