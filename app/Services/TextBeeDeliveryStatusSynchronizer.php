<?php

namespace App\Services;

use App\Models\NotificationStatus;
use App\Models\SmsNotification;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class TextBeeDeliveryStatusSynchronizer
{
    private const array STATUS_RANKS = [
        'pending' => 1,
        'accepted' => 1,
        'unknown' => 1,
        'dispatched' => 2,
        'sent' => 3,
        'delivered' => 4,
        'failed' => 4,
    ];

    public function applyStatus(
        SmsNotification $notification,
        string $status,
        ?string $messageId = null,
        ?CarbonInterface $occurredAt = null,
        ?string $eventId = null,
        ?string $errorCode = null,
    ): bool {
        $status = strtolower($status);

        if (! array_key_exists($status, self::STATUS_RANKS)) {
            return false;
        }

        return DB::transaction(function () use ($notification, $status, $messageId, $occurredAt, $eventId, $errorCode): bool {
            $sms = SmsNotification::query()->lockForUpdate()->find($notification->getKey());

            if ($sms === null || $sms->provider_name !== 'textbee') {
                return false;
            }

            $previousState = $sms->delivery_state;
            $previousUpdatedAt = $sms->provider_status_updated_at;

            if ($eventId !== null && $sms->provider_last_event_id === $eventId) {
                return false;
            }

            if ($previousState === 'delivered' && $status !== 'delivered') {
                return false;
            }

            $previousRank = $previousState === null ? 0 : (self::STATUS_RANKS[$previousState] ?? 0);
            $statusRank = self::STATUS_RANKS[$status];

            if ($statusRank < $previousRank) {
                return false;
            }

            if (
                $statusRank === $previousRank
                && $occurredAt !== null
                && $previousUpdatedAt !== null
                && $occurredAt->lt($previousUpdatedAt)
            ) {
                return false;
            }

            if ($previousState === 'accepted' && $status === 'pending') {
                return false;
            }

            $updates = [
                'delivery_state' => $status,
                'provider_status' => $status,
                'provider_status_updated_at' => $occurredAt ?? now(),
                'provider_status_checked_at' => now(),
            ];

            if ($messageId !== null) {
                $updates['provider_message_id'] = $messageId;
            }

            if ($eventId !== null) {
                $updates['provider_last_event_id'] = $eventId;
            }

            if ($status === 'failed') {
                $failedStatusId = NotificationStatus::query()->where('name', 'failed')->value('id');

                if ($failedStatusId !== null) {
                    $updates['notification_status_id'] = $failedStatusId;
                }

                $updates['failure_reason'] = filled($errorCode)
                    ? 'TextBee reported delivery failure ('.$errorCode.').'
                    : 'TextBee reported delivery failure.';
            } elseif (in_array($status, ['sent', 'delivered'], true)) {
                $sentStatusId = NotificationStatus::query()->where('name', 'sent')->value('id');

                if ($sentStatusId !== null) {
                    $updates['notification_status_id'] = $sentStatusId;
                }

                $updates['failure_reason'] = null;
            }

            $sms->update($updates);

            return true;
        });
    }
}
