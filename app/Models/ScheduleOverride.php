<?php

namespace App\Models;

use Database\Factories\ScheduleOverrideFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'override_date',
    'type',
    'start_time',
    'end_time',
    'reason',
])]
class ScheduleOverride extends Model
{
    /** @use HasFactory<ScheduleOverrideFactory> */
    use HasFactory;

    public const TYPE_CLOSED = 'closed';

    public const TYPE_EARLY_CLOSE = 'early_close';

    public const TYPE_PROVIDER_ABSENCE = 'provider_absence';

    /**
     * @return list<string>
     */
    public static function types(): array
    {
        return [
            self::TYPE_CLOSED,
            self::TYPE_EARLY_CLOSE,
            self::TYPE_PROVIDER_ABSENCE,
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'override_date' => 'date',
            'start_time' => 'datetime:H:i',
            'end_time' => 'datetime:H:i',
        ];
    }
}
