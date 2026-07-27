<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'appointment_id',
    'previous_scheduled_at',
    'new_scheduled_at',
    'initiated_by',
    'actor_id',
    'reason_category',
    'reason_details',
    'rescheduled_at',
    'notified_at',
])]
class AppointmentReschedule extends Model
{
    use HasFactory;

    /**
     * @return BelongsTo<Appointment, $this>
     */
    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'previous_scheduled_at' => 'datetime',
            'new_scheduled_at' => 'datetime',
            'rescheduled_at' => 'datetime',
            'notified_at' => 'datetime',
        ];
    }
}
