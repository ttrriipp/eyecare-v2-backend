<?php

namespace App\Http\Resources\Api;

use App\Enums\JobOrderStatus;
use App\Models\FrameRating;
use App\Models\JobOrder;
use App\Models\JobOrderItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * @mixin JobOrder
 */
class OpticalOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // Fetch patient's ratings for this order's items in one query
        $ratingsByVariant = $this->getRatingsByVariant($request);

        return [
            'id' => $this->id,
            'order_number' => $this->job_order_number,
            'status' => $this->status->value,
            'fulfillment_mode' => $this->fulfillment_mode,
            'total_amount' => number_format((float) $this->total_amount, 2, '.', ''),
            'started_at' => $this->started_at?->toIso8601String(),
            'ready_at' => $this->ready_at?->toIso8601String(),
            'dispensed_at' => $this->dispensed_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
            'source_quotation' => $this->quotation ? [
                'id' => $this->quotation->id,
                'quotation_number' => $this->quotation->quotation_number,
            ] : null,
            'items' => $this->items->map(fn (JobOrderItem $item) => [
                'id' => $item->id,
                'description' => $item->description,
                'quantity' => $item->quantity,
                'unit_price' => number_format((float) $item->unit_price, 2, '.', ''),
                'amount' => number_format((float) $item->amount, 2, '.', ''),
                'product_variant_id' => $item->product_variant_id,
                'image_url' => $this->getItemImageUrl($item),
                'item_kind' => $item->item_kind?->value,
                'lens_option_id' => $item->lens_option_id,
                'is_rateable' => $this->isItemRateable($item),
                'rating' => $this->getItemRating($item, $ratingsByVariant, $request),
            ]),
            'payment_summary' => $this->when($this->billingRecord, function () {
                $billing = $this->billingRecord;

                return [
                    'status' => $billing->status->value,
                    'total_amount' => number_format((float) $billing->total_amount, 2, '.', ''),
                    'amount_paid' => number_format((float) $billing->amount_paid, 2, '.', ''),
                    'balance_due' => number_format((float) $billing->balance_due, 2, '.', ''),
                    'payment_due_date' => $billing->payment_due_date?->format('Y-m-d'),
                    'is_overdue' => $billing->isOverdue(),
                ];
            }),
        ];
    }

    private function getItemImageUrl(JobOrderItem $item): ?string
    {
        if ($item->product_variant_id === null) {
            return null;
        }

        $variant = $item->variant;

        if ($variant?->product?->product_type !== 'frame') {
            return null;
        }

        return $this->firstCatalogImage($variant->images)
            ?? $this->firstCatalogImage($variant->product->images);
    }

    /**
     * @param  array<int, mixed>|null  $images
     */
    private function firstCatalogImage(?array $images): ?string
    {
        $image = collect($images ?? [])
            ->filter(fn (mixed $image): bool => is_string($image))
            ->map(fn (string $image): string => trim($image))
            ->first(fn (string $image): bool => $this->isPublicImageReference($image));

        return is_string($image) ? $image : null;
    }

    private function isPublicImageReference(string $image): bool
    {
        if (
            $image === ''
            || str_contains($image, '\\')
            || str_contains($image, '..')
            || str_starts_with($image, '/')
            || filter_var($image, FILTER_VALIDATE_URL) !== false
        ) {
            return false;
        }

        $path = parse_url($image, PHP_URL_PATH);

        if (! is_string($path)) {
            return false;
        }

        return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), [
            'avif',
            'bmp',
            'gif',
            'jpeg',
            'jpg',
            'png',
            'svg',
            'tif',
            'tiff',
            'webp',
        ], true);
    }

    /**
     * Check if an item is rateable (dispensed order + has product_variant_id).
     */
    private function isItemRateable($item): bool
    {
        return $this->status === JobOrderStatus::Dispensed
            && $item->product_variant_id !== null;
    }

    /**
     * Get the patient's rating for an item, if any.
     */
    private function getItemRating($item, $ratingsByVariant, Request $request): ?array
    {
        if ($item->product_variant_id === null) {
            return null;
        }

        $rating = $ratingsByVariant->get($item->product_variant_id);

        if ($rating === null) {
            return null;
        }

        $isAuthor = $request->user()?->patient_id === $rating->patient_id;

        return [
            'rating' => $rating->rating,
            'comment' => ($rating->is_hidden && ! $isAuthor) ? null : $rating->comment,
            'created_at' => $rating->created_at?->toIso8601String(),
            'revision_number' => 1,
        ];
    }

    /**
     * Fetch the patient's ratings for this order's product variants in one query.
     *
     * @return Collection<int, FrameRating>
     */
    private function getRatingsByVariant(Request $request)
    {
        $patient = $request->user()?->patient;

        if ($patient === null) {
            return collect();
        }

        $variantIds = $this->items
            ->whereNotNull('product_variant_id')
            ->pluck('product_variant_id')
            ->unique();

        if ($variantIds->isEmpty()) {
            return collect();
        }

        return FrameRating::query()
            ->where('patient_id', $patient->id)
            ->whereIn('product_variant_id', $variantIds)
            ->get()
            ->keyBy('product_variant_id');
    }
}
