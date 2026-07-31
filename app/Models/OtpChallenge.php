<?php

namespace App\Models;

use App\Enums\OtpPurpose;
use Database\Factories\OtpChallengeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class OtpChallenge extends Model
{
    /** @use HasFactory<OtpChallengeFactory> */
    use HasFactory;

    protected $fillable = [
        'public_id',
        'user_id',
        'purpose',
        'channel',
        'encrypted_destination',
        'destination_hash',
        'code_digest',
        'attempts',
        'max_attempts',
        'expires_at',
        'last_sent_at',
        'consumed_at',
        'invalidated_at',
        'delivery_status',
    ];

    protected function casts(): array
    {
        return [
            'purpose' => OtpPurpose::class,
            'encrypted_destination' => 'encrypted',
            'attempts' => 'integer',
            'max_attempts' => 'integer',
            'expires_at' => 'datetime',
            'last_sent_at' => 'datetime',
            'consumed_at' => 'datetime',
            'invalidated_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (OtpChallenge $challenge): void {
            if (blank($challenge->public_id)) {
                $challenge->public_id = (string) Str::uuid();
            }
        });
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isConsumed(): bool
    {
        return $this->consumed_at !== null;
    }

    public function isInvalidated(): bool
    {
        return $this->invalidated_at !== null;
    }

    public function isPending(): bool
    {
        return ! $this->isExpired()
            && ! $this->isConsumed()
            && ! $this->isInvalidated()
            && $this->attempts < $this->max_attempts;
    }

    public function hasAttemptsRemaining(): bool
    {
        return $this->attempts < $this->max_attempts;
    }

    public function incrementAttempts(): void
    {
        $this->increment('attempts');

        if ($this->attempts >= $this->max_attempts) {
            $this->update(['invalidated_at' => now()]);
        }
    }

    public function consume(): void
    {
        $this->update(['consumed_at' => now()]);
    }

    public function invalidate(): void
    {
        $this->update(['invalidated_at' => now()]);
    }

    public function markSent(): void
    {
        $this->update([
            'last_sent_at' => now(),
            'delivery_status' => 'sent',
        ]);
    }

    public function markFailed(): void
    {
        $this->update(['delivery_status' => 'failed']);
    }
}
