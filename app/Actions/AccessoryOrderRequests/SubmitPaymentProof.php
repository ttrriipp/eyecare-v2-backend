<?php

namespace App\Actions\AccessoryOrderRequests;

use App\Actions\Notifications\NotifyAdminUsers;
use App\Enums\AccessoryOrderRequestStatus;
use App\Enums\JobOrderStatus;
use App\Enums\OrderPaymentProofStatus;
use App\Models\AccessoryOrderRequest;
use App\Models\JobOrder;
use App\Models\OrderPaymentProof;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class SubmitPaymentProof
{
    public function __construct(private readonly NotifyAdminUsers $notifyAdminUsers) {}

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
        $senderName = trim($senderName);
        $referenceNumber = trim($referenceNumber);

        return DB::transaction(function () use ($account, $order, $file, $senderName, $referenceNumber): array {
            // Lock and validate
            $order = JobOrder::query()->lockForUpdate()->findOrFail($order->id);

            if ($order->patient_id !== $account->patient?->id) {
                abort(404);
            }

            // Idempotent: return existing proof before re-evaluating the
            // payment state so retries never create a second upload.
            $existing = OrderPaymentProof::query()
                ->where('job_order_id', $order->id)
                ->first();

            if ($existing !== null) {
                return ['proof' => $existing, 'created' => false];
            }

            $orderRequest = AccessoryOrderRequest::query()
                ->where('job_order_id', $order->id)
                ->lockForUpdate()
                ->first();

            if ($orderRequest === null || $orderRequest->status !== AccessoryOrderRequestStatus::Accepted) {
                throw ValidationException::withMessages([
                    'order' => ['This order is not awaiting payment.'],
                ]);
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

            // Validate file
            Validator::make(
                ['proof' => $file],
                ['proof' => ['required', 'file', 'mimes:jpg,jpeg,png', 'max:10240', 'dimensions:max_width=8000,max_height=8000']],
            )->validate();

            if (blank(trim($senderName)) || mb_strlen($senderName) > 100) {
                throw ValidationException::withMessages([
                    'sender_name' => ['Sender name is required and must be at most 100 characters.'],
                ]);
            }

            if (blank(trim($referenceNumber)) || mb_strlen($referenceNumber) > 100) {
                throw ValidationException::withMessages([
                    'reference_number' => ['Reference number is required and must be at most 100 characters.'],
                ]);
            }

            // Store privately
            $disk = (string) config('filesystems.payment_proof_disk');
            $path = $file->store('payment-proofs', $disk);
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

                $this->notifyAdminUsers->paymentProofSubmitted($proof->fresh(['jobOrder']));

                return ['proof' => $proof, 'created' => true];
            } catch (\Throwable $e) {
                // Clean up stored file if database write fails
                Storage::disk($disk)->delete($path);
                throw $e;
            }
        });
    }
}
