<?php

namespace App\Actions\AccessoryOrderRequests;

use App\Enums\JobOrderStatus;
use App\Enums\OrderPaymentProofStatus;
use App\Models\JobOrder;
use App\Models\OrderPaymentProof;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class SubmitPaymentProof
{
    /**
     * Submit a payment proof for a pending-payment order.
     *
     * @return array{proof: OrderPaymentProof, created: bool}
     */
    public function handle(
        User $account,
        JobOrder $order,
        UploadedFile $file,
        string $senderName,
        string $referenceNumber,
    ): array {
        return DB::transaction(function () use ($account, $order, $file, $senderName, $referenceNumber): array {
            // Lock and validate
            $order = JobOrder::query()->lockForUpdate()->findOrFail($order->id);

            if ($order->patient_id !== $account->patient?->id) {
                abort(404);
            }

            if ($order->status !== JobOrderStatus::PendingPayment) {
                throw ValidationException::withMessages([
                    'order' => ['This order is not awaiting payment.'],
                ]);
            }

            if ($order->payment_expires_at !== null && $order->payment_expires_at->isPast()) {
                throw ValidationException::withMessages([
                    'order' => ['The payment window has expired.'],
                ]);
            }

            // Idempotent: return existing proof if already submitted
            $existing = OrderPaymentProof::query()
                ->where('job_order_id', $order->id)
                ->first();

            if ($existing !== null) {
                return ['proof' => $existing, 'created' => false];
            }

            // Validate file
            $file->validate([
                'mimes' => 'jpg,jpeg,png',
                'max' => 5120, // 5MB
            ]);

            // Store privately
            $path = $file->store('payment-proofs', 'private');
            $originalName = $file->getClientOriginalName();
            $mimeType = $file->getMimeType();
            $fileSize = $file->getSize();

            try {
                // Create proof row
                $proof = OrderPaymentProof::create([
                    'job_order_id' => $order->id,
                    'user_id' => $account->id,
                    'status' => OrderPaymentProofStatus::Pending,
                    'file_path' => $path,
                    'original_name' => $originalName,
                    'mime_type' => $mimeType,
                    'file_size' => $fileSize,
                    'sender_name' => $senderName,
                    'reference_number' => $referenceNumber,
                ]);

                // Transition order to payment_review
                $order->update(['status' => JobOrderStatus::PaymentReview]);

                return ['proof' => $proof, 'created' => true];
            } catch (\Exception $e) {
                // Clean up stored file if database write fails
                Storage::disk('private')->delete($path);
                throw $e;
            }
        });
    }
}
