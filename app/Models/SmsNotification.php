<?php

namespace App\Models;

use Database\Factories\SmsNotificationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'appointment_id',
    'notification_status_id',
    'event',
    'recipient',
    'message',
])]
class SmsNotification extends Model
{
    /** @use HasFactory<SmsNotificationFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Appointment, $this>
     */
    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    /**
     * @return BelongsTo<NotificationStatus, $this>
     */
    public function status(): BelongsTo
    {
        return $this->belongsTo(NotificationStatus::class, 'notification_status_id');
    }
}
