<?php

namespace App\Actions\AccessoryOrderRequests;

use App\Enums\AccessoryOrderRequestStatus;
use App\Enums\DiscountProofStatus;
use App\Models\AccessoryOrderRequest;
use App\Models\AccessoryOrderRequestDiscountProof;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class SubmitDiscountProof
{
    /**
     * @return array{proof: AccessoryOrderRequestDiscountProof, created: bool}
     */
    public function handle(
        User $account,
        AccessoryOrderRequest $orderRequest,
        UploadedFile $file,
    ): array {
        return DB::transaction(function () use ($account, $orderRequest, $file): array {
            $lockedRequest = AccessoryOrderRequest::query()
                ->lockForUpdate()
                ->findOrFail($orderRequest->id);

            if (
                $lockedRequest->user_id !== $account->id
                || $lockedRequest->patient_id !== $account->patient?->id
            ) {
                abort(404);
            }

            if ($lockedRequest->requested_discount_type === 'none') {
                throw ValidationException::withMessages([
                    'order' => ['This order request does not include a discount request.'],
                ]);
            }

            if ($lockedRequest->status !== AccessoryOrderRequestStatus::Pending) {
                throw ValidationException::withMessages([
                    'order' => ['Only pending order requests can receive a discount proof.'],
                ]);
            }

            $existing = AccessoryOrderRequestDiscountProof::query()
                ->where('accessory_order_request_id', $lockedRequest->id)
                ->lockForUpdate()
                ->first();

            if ($existing !== null && $existing->status !== DiscountProofStatus::Rejected) {
                return ['proof' => $existing, 'created' => false];
            }

            Validator::make(
                ['proof' => $file],
                ['proof' => ['required', 'file', 'mimes:jpg,jpeg,png', 'max:10240', 'dimensions:max_width=8000,max_height=8000']],
            )->validate();

            $disk = (string) config('filesystems.discount_proof_disk', 'discount_proofs');
            $path = $file->store('discount-proofs', $disk);
            $oldPath = $existing?->file_path;

            try {
                if ($existing === null) {
                    $proof = AccessoryOrderRequestDiscountProof::create([
                        'accessory_order_request_id' => $lockedRequest->id,
                        'user_id' => $account->id,
                        'status' => DiscountProofStatus::Pending,
                        'file_path' => $path,
                        'original_name' => $file->getClientOriginalName(),
                        'mime_type' => $file->getMimeType(),
                        'file_size' => $file->getSize(),
                    ]);
                } else {
                    $existing->update([
                        'user_id' => $account->id,
                        'status' => DiscountProofStatus::Pending,
                        'file_path' => $path,
                        'original_name' => $file->getClientOriginalName(),
                        'mime_type' => $file->getMimeType(),
                        'file_size' => $file->getSize(),
                        'reviewed_by' => null,
                        'reviewed_at' => null,
                        'rejection_reason' => null,
                    ]);
                    $proof = $existing->fresh();
                }
            } catch (\Throwable $exception) {
                Storage::disk($disk)->delete($path);
                throw $exception;
            }

            if ($oldPath !== null) {
                Storage::disk($disk)->delete($oldPath);
            }

            return ['proof' => $proof, 'created' => $existing === null];
        });
    }
}
