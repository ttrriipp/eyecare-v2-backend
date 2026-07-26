<?php

namespace App\Actions\Privacy;

use App\Models\LegalHold;
use App\Models\RetentionPolicy;
use Illuminate\Support\Carbon;

class EvaluateRetention
{
    /**
     * Check whether a record category is eligible for disposal.
     *
     * Returns false if:
     * - No retention policy exists for the category
     * - A legal hold is active
     * - The retention period has not elapsed
     * - Auto-purge is disabled
     */
    public function isEligibleForDisposal(string $category, Carbon $recordCreatedAt): bool
    {
        $policy = RetentionPolicy::query()
            ->where('category', $category)
            ->first();

        if ($policy === null) {
            return false;
        }

        if (! $policy->auto_purge_enabled) {
            return false;
        }

        // Check for active legal holds
        $hasActiveHold = LegalHold::query()
            ->where('is_active', true)
            ->exists();

        if ($hasActiveHold) {
            return false;
        }

        if ($policy->retention_days === null) {
            return false;
        }

        $disposalDate = $recordCreatedAt->addDays($policy->retention_days);

        return Carbon::now()->gte($disposalDate);
    }
}
