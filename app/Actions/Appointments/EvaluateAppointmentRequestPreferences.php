<?php

namespace App\Actions\Appointments;

use App\Models\AppointmentRequest;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

class EvaluateAppointmentRequestPreferences
{
    public function __construct(private readonly EvaluateAppointmentAvailability $evaluateAvailability) {}

    /**
     * Evaluate each submitted preference in the order it was received.
     *
     * @return array<int, array{
     *     preference: string,
     *     starts_at: CarbonInterface,
     *     ends_at: CarbonInterface,
     *     available: bool,
     *     reason: ?string,
     * }>
     */
    public function handle(
        AppointmentRequest $request,
        int $durationMinutes,
        ?User $optometrist = null,
    ): array {
        return collect($request->getAllTimePreferences())
            ->values()
            ->map(function (string $preference, int $index) use ($durationMinutes, $optometrist): array {
                $decision = $this->evaluateAvailability->handle(
                    startsAt: Carbon::parse($preference),
                    durationMinutes: $durationMinutes,
                    optometrist: $optometrist,
                    enforceFuture: true,
                    enforceGrid: true,
                );

                return [
                    'preference' => $index === 0 ? 'Primary preference' : 'Alternative '.$index,
                    'starts_at' => $decision->startsAt,
                    'ends_at' => $decision->endsAt,
                    'available' => $decision->available,
                    'reason' => $decision->reason,
                ];
            })
            ->all();
    }
}
