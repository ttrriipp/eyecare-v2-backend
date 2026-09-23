<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\SmsNotification;
use App\Services\TextBeeDeliveryStatusSynchronizer;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use JsonException;
use Symfony\Component\HttpFoundation\Response;

class TextBeeWebhookController extends Controller
{
    public function __invoke(Request $request, TextBeeDeliveryStatusSynchronizer $synchronizer): Response
    {
        $rawBody = $request->getContent();
        $secret = (string) config('services.textbee.webhook_secret', '');
        $signature = (string) $request->header('X-Signature', '');

        if ($secret === '') {
            return response()->json(['message' => 'TextBee webhook is not configured.'], 503);
        }

        $expectedSignature = hash_hmac('sha256', $rawBody, $secret);

        if ($signature === '' || ! hash_equals($expectedSignature, $signature)) {
            return response()->json(['message' => 'Invalid webhook signature.'], 401);
        }

        try {
            $payload = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return response()->json(['message' => 'Invalid webhook payload.'], 400);
        }

        if (! is_array($payload)) {
            return response()->json(['message' => 'Invalid webhook payload.'], 400);
        }

        $validator = Validator::make($payload, [
            'webhookEvent' => ['required', 'string', 'max:80'],
            'idempotencyKey' => ['required', 'string', 'max:191'],
            'smsId' => ['nullable', 'string', 'max:191'],
            'smsBatchId' => ['nullable', 'string', 'max:191'],
            'status' => ['nullable', 'string', 'max:80'],
            'sentAt' => ['nullable', 'date'],
            'deliveredAt' => ['nullable', 'date'],
            'failedAt' => ['nullable', 'date'],
            'errorCode' => ['nullable', 'string', 'max:80'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Invalid webhook payload.'], 422);
        }

        $event = (string) $payload['webhookEvent'];
        $eventStatus = match ($event) {
            'MESSAGE_SENT' => 'sent',
            'MESSAGE_DELIVERED' => 'delivered',
            'MESSAGE_FAILED' => 'failed',
            default => null,
        };

        if ($eventStatus === null) {
            return response()->noContent();
        }

        $occurredAtField = match ($event) {
            'MESSAGE_SENT' => 'sentAt',
            'MESSAGE_DELIVERED' => 'deliveredAt',
            'MESSAGE_FAILED' => 'failedAt',
        };
        $batchId = filled($payload['smsBatchId'] ?? null) ? (string) $payload['smsBatchId'] : null;
        $messageId = filled($payload['smsId'] ?? null) ? (string) $payload['smsId'] : null;

        if ($batchId === null && $messageId === null) {
            return response()->json(['message' => 'SMS identifier is required.'], 422);
        }

        $idempotencyKey = (string) $payload['idempotencyKey'];
        $occurredAt = filled($payload[$occurredAtField] ?? null)
            ? Carbon::parse((string) $payload[$occurredAtField])
            : now();
        $errorCode = isset($payload['errorCode'])
            ? substr((string) preg_replace('/[^A-Za-z0-9_.:-]/', '', (string) $payload['errorCode']), 0, 64)
            : null;

        $result = DB::transaction(function () use (
            $batchId,
            $errorCode,
            $event,
            $eventStatus,
            $idempotencyKey,
            $messageId,
            $occurredAt,
            $synchronizer,
        ): string {
            $isDuplicate = DB::table('sms_provider_webhook_events')
                ->where('provider', 'textbee')
                ->where('idempotency_key', $idempotencyKey)
                ->exists();

            if ($isDuplicate) {
                return 'duplicate';
            }

            $notificationQuery = SmsNotification::query()
                ->where('provider_name', 'textbee')
                ->where(function ($query) use ($batchId, $messageId): void {
                    if ($batchId !== null) {
                        $query->where('provider_reference', $batchId);
                    }

                    if ($messageId !== null) {
                        $method = $batchId === null ? 'where' : 'orWhere';
                        $query->{$method}('provider_message_id', $messageId);
                    }
                });
            $notification = $notificationQuery->lockForUpdate()->first();

            if ($notification === null) {
                return 'unmatched';
            }

            $inserted = DB::table('sms_provider_webhook_events')->insertOrIgnore([
                'provider' => 'textbee',
                'idempotency_key' => $idempotencyKey,
                'event_name' => $event,
                'sms_notification_id' => $notification->getKey(),
                'provider_reference' => $batchId,
                'provider_message_id' => $messageId,
                'provider_status' => $eventStatus,
                'occurred_at' => $occurredAt,
                'received_at' => now(),
                'processed_at' => now(),
            ]);

            if ($inserted !== 1) {
                return 'duplicate';
            }

            $synchronizer->applyStatus(
                notification: $notification,
                status: $eventStatus,
                messageId: $messageId,
                occurredAt: $occurredAt,
                eventId: $idempotencyKey,
                errorCode: $errorCode,
            );

            return 'processed';
        });

        if ($result === 'unmatched') {
            return response()->json(['message' => 'SMS notification not found.'], 503);
        }

        return response()->noContent();
    }
}
