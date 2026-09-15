<?php

namespace Ygpynet\Giveaways;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A queued point refund that failed at refund time and awaits retry by
 * giveaways:retry-refunds. Kept forever as an audit trail (refunded_at null
 * = still pending), hence no foreign keys on the table.
 *
 * @property int $id
 * @property int $giveaway_id
 * @property int $user_id
 * @property int $amount
 * @property string $reason
 * @property int $attempts
 * @property string|null $last_error
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $refunded_at
 * @property \Flarum\User\User $user
 */
class GiveawayRefund extends AbstractModel
{
    protected $table = 'giveaway_refunds';

    public $timestamps = false;

    protected $casts = [
        'amount'      => 'integer',
        'attempts'    => 'integer',
        'created_at'  => 'datetime',
        'refunded_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function record(int $giveawayId, int $userId, int $amount, string $reason, string $error): self
    {
        $refund = new self();
        $refund->giveaway_id = $giveawayId;
        $refund->user_id = $userId;
        $refund->amount = $amount;
        $refund->reason = $reason;
        $refund->attempts = 0;
        $refund->last_error = self::safeError($error);
        $refund->created_at = Carbon::now();
        $refund->save();

        return $refund;
    }

    /**
     * Byte-safe truncation for the VARCHAR(255) last_error column: mb_substr
     * counts CHARACTERS, so a multibyte message could still overflow the
     * column; cut on bytes, then drop any trailing partial sequence so the
     * value always inserts under utf8mb4 strict mode.
     */
    public static function safeError(string $error): string
    {
        $cut = substr($error, 0, 250);

        while ($cut !== '' && ! mb_check_encoding($cut, 'UTF-8')) {
            $cut = substr($cut, 0, -1);
        }

        return $cut;
    }

    /** Unrefunded rows, oldest first — the giveaways:retry-refunds work list. */
    public static function pending()
    {
        return static::query()->whereNull('refunded_at')->orderBy('id');
    }

    public function isPending(): bool
    {
        return $this->refunded_at === null;
    }
}
