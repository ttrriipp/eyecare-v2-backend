<?php

namespace App\Models;

use Database\Factories\SmsNotificationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'appointment_id',
    'job_order_id',
    'notification_status_id',
    'event',
    'recipient',
    'message',
    'failure_reason',
    'provider_name',
    'provider_reference',
    'provider_message_id',
    'provider_status',
    'delivery_state',
    'send_attempt_count',
    'send_attempted_at',
    'provider_accepted_at',
    'provider_status_updated_at',
    'provider_status_checked_at',
    'provider_last_event_id',
])]
class SmsNotification extends Model
{
    /** @use HasFactory<SmsNotificationFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'send_attempt_count' => 'integer',
            'provider_accepted_at' => 'datetime',
            'send_attempted_at' => 'datetime',
            'provider_status_updated_at' => 'datetime',
            'provider_status_checked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Appointment, $this>
     */
    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    /**
     * @return BelongsTo<JobOrder, $this>
     */
    public function jobOrder(): BelongsTo
    {
        return $this->belongsTo(JobOrder::class);
    }

    /**
     * @return BelongsTo<NotificationStatus, $this>
     */
    public function status(): BelongsTo
    {
        return $this->belongsTo(NotificationStatus::class, 'notification_status_id');
    }
}
