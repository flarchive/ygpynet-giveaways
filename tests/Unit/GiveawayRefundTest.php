<?php

/*
 * This file is part of ygpynet/giveaways.
 *
 * Licensed under the MIT license.
 */

namespace Ygpynet\Giveaways\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ygpynet\Giveaways\GiveawayRefund;

class GiveawayRefundTest extends TestCase
{
    public function test_safe_error_passes_short_messages_through(): void
    {
        $this->assertSame('boom', GiveawayRefund::safeError('boom'));
        $this->assertSame('', GiveawayRefund::safeError(''));
    }

    public function test_safe_error_truncates_multibyte_input_to_a_column_valid_value(): void
    {
        // Regression: mb_substr(…, 255) counted CHARACTERS — a message of
        // 4-byte runes could reach 1020 BYTES and overflow the VARCHAR(255)
        // column, making the refund queueing itself fail.
        $long = str_repeat('积分退款失败💥', 200);

        $cut = GiveawayRefund::safeError($long);

        $this->assertLessThanOrEqual(255, strlen($cut), 'must fit the column in BYTES');
        $this->assertTrue(mb_check_encoding($cut, 'UTF-8'), 'must not end on a partial sequence');
        $this->assertNotSame('', $cut);
    }

    public function test_safe_error_keeps_ascii_tail_room_for_operators(): void
    {
        $cut = GiveawayRefund::safeError(str_repeat('a', 500));

        $this->assertSame(250, strlen($cut));
    }
}
