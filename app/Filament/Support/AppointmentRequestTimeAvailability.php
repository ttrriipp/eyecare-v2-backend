<?php

namespace App\Filament\Support;

use App\Actions\Appointments\EvaluateAppointmentRequestPreferences;
use App\Models\AppointmentRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

final class AppointmentRequestTimeAvailability
{
    /**
     * Build the preferred-time summary displayed in the request queue.
     *
     * @return array{
     *     show_availability: bool,
     *     preferences: list<array{
     *         label: string,
     *         time: string,
     *         available: bool|null,
     *         availability_label: string|null,
     *     }>,
     * }
     */
    public static function forTable(AppointmentRequest $request): array
    {
        $showAvailability = $request->isPending();
        $decisions = $showAvailability
            ? app(EvaluateAppointmentRequestPreferences::class)->handle(
                request: $request,
                durationMinutes: (int) ($request->provisional_duration_minutes
                    ?? $request->appointmentType?->duration_minutes
                    ?? 30),
            )
            : [];

        $preferences = collect($request->getAllTimePreferences())
            ->values()
            ->map(function (string $time, int $index) use ($decisions, $showAvailability): array {
                $decision = $decisions[$index] ?? null;
                $available = $showAvailability ? ($decision['available'] ?? null) : null;

                return [
                    'label' => $index === 0 ? 'Primary' : "Alt {$index}",
                    'time' => Carbon::parse($time)->format('M j, g:i A'),
                    'available' => $available,
                    'availability_label' => $showAvailability && $available !== null
                        ? self::availabilityLabel($decision['reason'] ?? null)
                        : null,
                ];
            })
            ->values()
            ->all();

        return [
            'show_availability' => $showAvailability,
            'preferences' => $preferences,
        ];
    }

    private static function availabilityLabel(?string $reason): string
    {
        return match ($reason) {
            null => 'Available',
            'clinic_closed' => 'Clinic closed',
            'outside_clinic_hours' => 'Outside clinic hours',
            'capacity_reached' => 'No longer available',
            'elapsed' => 'Elapsed',
            'outside_slot_grid' => 'Outside available time grid',
            default => Str::headline($reason),
        };
    }
}
