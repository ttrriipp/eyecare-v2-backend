<?php

namespace App\Filament\Support;

use App\Filament\Resources\Patients\PatientResource;
use App\Models\Patient;
use Illuminate\Support\HtmlString;

final class PreferredFramesSummary
{
    /**
     * Render the compact preferred-frame summary used by appointment and
     * consultation forms.
     */
    public static function render(?Patient $patient): HtmlString
    {
        $account = $patient?->account;

        if ($account === null) {
            return new HtmlString(
                '<div class="text-sm text-gray-500 dark:text-gray-400">No linked account</div>',
            );
        }

        $total = $account->savedFrames()->count();
        $frames = $account->savedFrames()
            ->withCatalogData()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(3)
            ->get();

        return new HtmlString(view('filament.components.preferred-frames-summary', [
            'frames' => $frames,
            'patientUrl' => PatientResource::getUrl('edit', ['record' => $patient]),
            'total' => $total,
        ])->render());
    }
}
