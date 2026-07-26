<?php

namespace App\Actions\Ratings;

use App\Models\FrameRating;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ModerateFrameRating
{
    /**
     * Hide a rating comment while preserving the star value in aggregates.
     */
    public function handle(
        FrameRating $rating,
        string $reason,
        User $moderator,
    ): FrameRating {
        if ($rating->is_hidden) {
            throw ValidationException::withMessages([
                'rating' => ['This rating is already hidden.'],
            ]);
        }

        return DB::transaction(function () use ($rating, $reason, $moderator): FrameRating {
            $rating->update([
                'is_hidden' => true,
                'moderation_reason' => $reason,
                'moderated_by' => $moderator->id,
                'moderated_at' => now(),
            ]);

            return $rating->fresh();
        });
    }

    /**
     * Restore a hidden rating comment.
     */
    public function restore(FrameRating $rating, User $moderator): FrameRating
    {
        if (! $rating->is_hidden) {
            return $rating;
        }

        $rating->update([
            'is_hidden' => false,
            'moderation_reason' => null,
            'moderated_by' => $moderator->id,
            'moderated_at' => now(),
        ]);

        return $rating->fresh();
    }
}
