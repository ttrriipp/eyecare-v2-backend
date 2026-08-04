<?php

namespace App\Filament\Support;

use App\Filament\Resources\Patients\PatientResource;
use App\Models\Patient;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;

class PatientDuplicateMatchCard
{
    /**
     * Render a list of possibly-matching patients as a card.
     *
     * @param  Collection<int, Patient>  $matches
     */
    public static function render(Collection $matches, string $emptyMessage = ''): HtmlString
    {
        if ($matches->isEmpty()) {
            if (empty($emptyMessage)) {
                return new HtmlString('');
            }

            return new HtmlString(
                '<div class="rounded-lg border border-dashed border-gray-300 p-3 text-center text-sm text-gray-500 dark:border-white/10 dark:text-gray-400">'
                .e($emptyMessage).'</div>'
            );
        }

        $rows = $matches->map(function (Patient $patient): string {
            $url = e(PatientResource::getUrl('edit', ['record' => $patient]));
            $name = e($patient->full_name);
            $dob = $patient->date_of_birth?->format('M j, Y');
            $meta = e(collect([$patient->patient_number, $dob !== null ? "DOB {$dob}" : null])
                ->filter()
                ->implode(' · '));

            return <<<HTML
                <a href="{$url}" target="_blank" class="flex items-center gap-x-3 p-3 transition hover:bg-gray-50 dark:hover:bg-white/5">
                    <span class="flex size-9 shrink-0 items-center justify-center rounded-full bg-warning-50 text-warning-600 dark:bg-warning-500/15 dark:text-warning-400">
                        <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M17.982 18.725A7.488 7.488 0 0 0 12 15.75a7.488 7.488 0 0 0-5.982 2.975m11.963 0a9 9 0 1 0-11.963 0m11.963 0A8.966 8.966 0 0 1 12 21a8.966 8.966 0 0 1-5.982-2.275M15 9.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/>
                        </svg>
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm font-semibold text-gray-900 dark:text-white">{$name}</span>
                        <span class="block truncate text-xs text-gray-500 dark:text-gray-400">{$meta}</span>
                    </span>
                    <svg class="size-4 shrink-0 text-gray-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/>
                    </svg>
                </a>
                HTML;
        })->implode('<div class="border-t border-gray-200 dark:border-white/10"></div>');

        return new HtmlString(
            '<div class="overflow-hidden rounded-lg border border-warning-200 dark:border-warning-500/20">'.$rows.'</div>'
        );
    }
}
