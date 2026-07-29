<?php

namespace App\Http\Resources;

use App\Services\Eyewear\EyewearAggregate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin EyewearAggregate
 */
class EyewearDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $data = [
            'key' => $this->key,
            'description' => $this->description,
            'consultation_at' => $this->consultationAt,
            'created_at' => $this->createdAt,
            'progress' => $this->progress->value,
            'payment_status' => $this->paymentStatus?->value,
            'total_amount' => $this->totalAmount,
            'balance_due' => $this->balanceDue,
            'activity_at' => $this->activityAt,
        ];

        // Only include sections when their source records exist
        if ($this->estimate !== null) {
            $data['estimate'] = $this->estimate;
        }

        if ($this->preparation !== null) {
            $data['preparation'] = $this->preparation;
        }

        if ($this->dispensing !== null) {
            $data['dispensing'] = $this->dispensing;
        }

        if ($this->paymentSummary !== null) {
            $data['payment_summary'] = $this->paymentSummary;
        }

        return $data;
    }
}
