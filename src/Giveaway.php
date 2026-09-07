<?php

namespace ErnestDefoe\Giveaways;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int|null $user_id
 * @property string $title
 * @property string $slug
 * @property string $prize
 * @property string|null $description
 * @property string|null $description_html
 * @property string|null $cover_url
 * @property int $winner_count
 * @property string $status
 * @property \Carbon\Carbon|null $starts_at
 * @property \Carbon\Carbon $ends_at
 * @property string|null $settings
 * @property string|null $draw_seed
 * @property string|null $entrant_hash
 * @property \Carbon\Carbon|null $drawn_at
 * @property int|null $category_id
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $updated_at
 * @property \Flarum\User\User|null $user
 * @property \ErnestDefoe\Giveaways\GiveawayCategory|null $category
 */
class Giveaway extends AbstractModel
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_DRAWN = 'drawn';
    public const STATUS_CANCELLED = 'cancelled';

    protected $table = 'giveaways';

    /**
     * Allowed status transitions. drawn/cancelled are terminal; the
     * active → drawn move belongs to DrawService alone (it carries the
     * provably-fair bookkeeping), everything else goes through explicit
     * endpoints that validate with canTransitionTo().
     */
    protected const TRANSITIONS = [
        self::STATUS_DRAFT     => [self::STATUS_DRAFT, self::STATUS_ACTIVE, self::STATUS_CANCELLED],
        self::STATUS_ACTIVE    => [self::STATUS_DRAWN, self::STATUS_CANCELLED],
        self::STATUS_DRAWN     => [],
        self::STATUS_CANCELLED => [],
    ];

    /** Atomically claim a status change (WHERE status IN $from), like draw() does. */
    public function claimStatus(array $from, string $to): bool
    {
        $claimed = static::query()
            ->whereKey($this->id)
            ->whereIn('status', $from)
            ->update(['status' => $to]);

        if ($claimed) {
            $this->status = $to;
        }

        return (bool) $claimed;
    }

    public function canTransitionTo(string $status): bool
    {
        return in_array($status, self::TRANSITIONS[$this->status] ?? [], true);
    }

    /**
     * Whether attributes may still be edited. Drawn/cancelled giveaways are
     * published records — their prize text, times and fairness data are
     * already public, so mutating them would rewrite history.
     */
    public function isEditable(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_ACTIVE], true);
    }

    protected $casts = [
        'starts_at'    => 'datetime',
        'ends_at'      => 'datetime',
        'drawn_at'     => 'datetime',
        'winner_count' => 'integer',
    ];

    public function entries(): HasMany
    {
        return $this->hasMany(GiveawayEntry::class);
    }

    public function winners(): HasMany
    {
        return $this->hasMany(GiveawayWinner::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(GiveawayCategory::class, 'category_id');
    }

    /**
     * Can $actor manage this giveaway? True for global managers, or for the
     * giveaway's own author who still holds the create permission. Centralised
     * here so the presenter and every controller share one rule.
     */
    public function canBeManagedBy(User $actor): bool
    {
        return $actor->hasPermission('giveaways.manage')
            || ($this->user_id && (int) $actor->id === (int) $this->user_id && $actor->hasPermission('giveaways.create'));
    }

    /** Decoded settings (entry methods + eligibility) with defaults. */
    public function settingsArray(): array
    {
        $s = json_decode((string) $this->settings, true) ?: [];
        return array_merge([
            'post_bonus'         => 0,   // bonus entries for posting during the window (0 = off)
            'min_posts'          => 0,
            'min_age_days'       => 0,
            'entry_cost_points'  => 0,   // points charged on entry, ramon/point-system balance (0 = free)
            'claim_instructions' => '',  // shown to winners when they claim their prize
        ], $s);
    }

    public function isRunning(): bool
    {
        $now = Carbon::now();
        return $this->status === 'active'
            && (! $this->starts_at || $this->starts_at->lte($now))
            && $this->ends_at->gt($now);
    }

    /**
     * Whether this giveaway is over for participants: drawn, cancelled, or its
     * window has expired (still 'active' but past the end time). Unlike
     * isRunning(), a not-yet-started giveaway is NOT "ended".
     */
    public function hasEnded(): bool
    {
        return in_array($this->status, ['drawn', 'cancelled'], true)
            || ($this->status === 'active' && $this->ends_at->lte(Carbon::now()));
    }
}
