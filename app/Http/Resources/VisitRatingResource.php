<?php

namespace App\Http\Resources;

use App\Models\VisitRating;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin VisitRating
 */
class VisitRatingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $isAuthor = $request->user()?->patient?->id === $this->patient_id;

        return [
            'id' => $this->id,
            'rating' => $this->rating,
            'comment' => $this->shouldShowComment($isAuthor) ? $this->comment : null,
            'revision_number' => 1,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }

    private function shouldShowComment(bool $isAuthor): bool
    {
        if (! $this->is_hidden) {
            return true;
        }

        // Author can see their own hidden comment
        return $isAuthor;
    }
}
