<?php

namespace App\Filament\Support;

use App\Filament\Resources\Patients\PatientResource;
use App\Filament\Resources\Products\ProductResource;
use App\Models\Patient;
use App\Models\SavedFrame;
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
        $frameUrls = $frames->mapWithKeys(function (SavedFrame $frame): array {
            $product = $frame->variant?->product;

            return [
                $frame->id => $product === null
                    ? null
                    : ProductResource::getUrl('edit', ['record' => $product]),
            ];
        })->all();

        return new HtmlString(view('filament.components.preferred-frames-summary', [
            'frames' => $frames,
            'frameUrls' => $frameUrls,
            'patientUrl' => PatientResource::getUrl('edit', ['record' => $patient]),
            'total' => $total,
        ])->render());
    }
}
