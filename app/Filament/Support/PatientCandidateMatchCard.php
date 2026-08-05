<?php

namespace App\Filament\Support;

use App\Filament\Resources\Patients\PatientResource;
use App\Models\Patient;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

class PatientCandidateMatchCard
{
    /**
     * Render ranked patient candidates (from RankPatientCandidates) as a card.
     *
     * @param  Collection<int, array{patient: Patient, strength: string, reasons: array<int, string>}>  $candidates
     */
    public static function render(Collection $candidates, string $emptyMessage = 'No matching patients found.'): HtmlString
    {
        if ($candidates->isEmpty()) {
            return new HtmlString(
                '<div class="rounded-lg border border-dashed border-gray-300 p-3 text-center text-sm text-gray-500 dark:border-white/10 dark:text-gray-400">'
                .e($emptyMessage).'</div>'
            );
        }

        $theme = [
            'strong' => [
                'chip' => 'bg-success-50 text-success-600 dark:bg-success-500/15 dark:text-success-400',
                'badge' => 'bg-success-50 text-success-700 dark:bg-success-500/15 dark:text-success-400',
            ],
            'moderate' => [
                'chip' => 'bg-warning-50 text-warning-600 dark:bg-warning-500/15 dark:text-warning-400',
                'badge' => 'bg-warning-50 text-warning-700 dark:bg-warning-500/15 dark:text-warning-400',
            ],
            'weak' => [
                'chip' => 'bg-gray-100 text-gray-500 dark:bg-white/5 dark:text-gray-400',
                'badge' => 'bg-gray-100 text-gray-600 dark:bg-white/5 dark:text-gray-400',
            ],
        ];

        $rows = $candidates->map(function (array $candidate) use ($theme): string {
            $patient = $candidate['patient'];
            $colors = $theme[$candidate['strength']] ?? $theme['weak'];

            $url = e(PatientResource::getUrl('edit', ['record' => $patient]));
            $name = e($patient->full_name);
            $strengthLabel = e(Str::headline($candidate['strength']));
            $meta = e($patient->patient_number);

            $reasons = collect($candidate['reasons'])
                ->map(fn (string $reason): string => Str::headline($reason))
                ->implode(' · ');
            $reasonsHtml = $reasons !== ''
                ? '<span class="block truncate text-xs text-gray-500 dark:text-gray-400">'.e($reasons).'</span>'
                : '';

            return <<<HTML
                <a href="{$url}" target="_blank" class="flex items-center gap-x-3 p-3 transition hover:bg-gray-50 dark:hover:bg-white/5">
                    <span class="flex size-9 shrink-0 items-center justify-center rounded-full {$colors['chip']}">
                        <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M17.982 18.725A7.488 7.488 0 0 0 12 15.75a7.488 7.488 0 0 0-5.982 2.975m11.963 0a9 9 0 1 0-11.963 0m11.963 0A8.966 8.966 0 0 1 12 21a8.966 8.966 0 0 1-5.982-2.275M15 9.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/>
                        </svg>
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm font-semibold text-gray-900 dark:text-white">{$name}</span>
                        <span class="block truncate text-xs text-gray-500 dark:text-gray-400">{$meta}</span>
                        {$reasonsHtml}
                    </span>
                    <span class="inline-flex shrink-0 items-center rounded-md px-2 py-0.5 text-xs font-medium {$colors['badge']}">{$strengthLabel}</span>
                    <svg class="size-4 shrink-0 text-gray-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/>
                    </svg>
                </a>
                HTML;
        })->implode('<div class="border-t border-gray-200 dark:border-white/10"></div>');

        return new HtmlString(
            '<div class="rounded-lg border border-gray-200 dark:border-white/10">'
            .$rows
            .'</div>'
        );
    }
}
