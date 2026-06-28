<?php

namespace App\Http\Resources;

use App\Models\Feedback;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Feedback
 */
class FeedbackResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'appointment_id' => $this->appointment_id,
            'order_id' => $this->order_id,
            'rating' => $this->rating,
            'comment' => $this->comment,
        ];
    }
}
