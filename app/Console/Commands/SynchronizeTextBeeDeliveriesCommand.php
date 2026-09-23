<?php

namespace App\Console\Commands;

use App\Models\NotificationStatus;
use App\Models\SmsNotification;
use App\Services\TextBeeDeliveryStatusSynchronizer;
use App\Services\TextBeeService;
use Carbon\Carbon;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('sms:textbee:sync')]
#[Description('Synchronize TextBee SMS delivery statuses')]
class SynchronizeTextBeeDeliveriesCommand extends Command
{
    public function handle(TextBeeService $textBee, TextBeeDeliveryStatusSynchronizer $synchronizer): int
    {
        if (! $textBee->isEnabled()) {
            $this->info('TextBee is disabled; delivery status sync skipped.');

            return self::SUCCESS;
        }

        $failedStatusId = NotificationStatus::query()->where('name', 'failed')->value('id');

        if ($failedStatusId === null) {
            $this->error('The failed notification status is missing.');

            return self::FAILURE;
        }

        $staleSending = SmsNotification::query()
            ->where('provider_name', 'textbee')
            ->where('delivery_state', 'sending')
            ->where('send_attempted_at', '<=', now()->subMinutes(10))
            ->update([
                'notification_status_id' => $failedStatusId,
                'delivery_state' => 'unknown',
                'provider_status' => 'unknown',
                'provider_status_updated_at' => now(),
                'failure_reason' => 'TextBee send was interrupted before its outcome was recorded. Verify provider status before retrying.',
            ]);

        $untrackableAccepted = SmsNotification::query()
            ->where('provider_name', 'textbee')
            ->where('delivery_state', 'accepted')
            ->whereNull('provider_reference')
            ->where('provider_accepted_at', '<=', now()->subMinutes(30))
            ->update([
                'notification_status_id' => $failedStatusId,
                'delivery_state' => 'unknown',
                'provider_status' => 'unknown',
                'provider_status_updated_at' => now(),
                'failure_reason' => 'TextBee accepted the SMS without a pollable batch reference. Verify provider status before retrying.',
            ]);

        $pending = SmsNotification::query()
            ->where('provider_name', 'textbee')
            ->whereIn('delivery_state', ['accepted', 'pending', 'dispatched', 'sent'])
            ->whereNotNull('provider_reference')
            ->where(fn ($query) => $query
                ->whereNull('provider_status_checked_at')
                ->orWhere('provider_status_checked_at', '<=', now()->subMinutes(5)))
            ->orderBy('provider_status_checked_at')
            ->limit(100)
            ->get()
            ->groupBy('provider_reference');

        $updated = 0;

        foreach ($pending as $batchId => $notifications) {
            $messages = $textBee->messagesForBatch((string) $batchId);

            SmsNotification::query()
                ->whereKey($notifications->modelKeys())
                ->update(['provider_status_checked_at' => now()]);

            if ($messages === null) {
                continue;
            }

            foreach ($messages as $message) {
                $messageId = isset($message['_id']) ? (string) $message['_id'] : null;
                $notification = $messageId === null
                    ? $notifications->first()
                    : ($notifications->firstWhere('provider_message_id', $messageId) ?? $notifications->first());
                $status = strtolower((string) ($message['status'] ?? ''));

                if ($notification === null || $status === '') {
                    continue;
                }

                $occurredAt = null;

                foreach (['deliveredAt', 'sentAt', 'updatedAt'] as $timestampField) {
                    if (filled($message[$timestampField] ?? null)) {
                        try {
                            $occurredAt = Carbon::parse((string) $message[$timestampField]);
                        } catch (\Throwable) {
                            $occurredAt = null;
                        }

                        break;
                    }
                }

                $didUpdate = $synchronizer->applyStatus(
                    notification: $notification,
                    status: $status,
                    messageId: $messageId,
                    occurredAt: $occurredAt,
                    errorCode: isset($message['errorCode']) ? (string) $message['errorCode'] : null,
                );

                if ($didUpdate) {
                    $updated++;
                }
            }
        }

        $this->info(sprintf(
            'Checked %d TextBee batch(es), updated %d notification(s), and marked %d unresolved send(s) as unknown.',
            $pending->count(),
            $updated,
            $staleSending + $untrackableAccepted,
        ));

        return self::SUCCESS;
    }
}
