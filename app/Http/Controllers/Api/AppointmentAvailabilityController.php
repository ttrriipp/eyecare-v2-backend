<?php

namespace App\Http\Controllers\Api;

use App\Actions\Appointments\ListAvailableAppointmentSlots;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\AppointmentAvailabilityRequest;
use App\Models\User;
use App\Models\VisitReason;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

class AppointmentAvailabilityController extends Controller
{
    public function __invoke(
        AppointmentAvailabilityRequest $request,
        ListAvailableAppointmentSlots $listAvailableAppointmentSlots,
    ): JsonResponse {
        $visitReason = VisitReason::query()->findOrFail($request->validated('visit_reason_id'));
        $optometrist = $request->filled('optometrist_id')
            ? User::query()->findOrFail($request->validated('optometrist_id'))
            : null;

        $slots = $listAvailableAppointmentSlots->handle(
            date: Carbon::createFromFormat('Y-m-d', $request->validated('date'), config('app.timezone')),
            visitReason: $visitReason,
            optometrist: $optometrist,
        );

        return response()->json([
            'data' => collect($slots)->map->toIso8601String()->all(),
        ]);
    }
}
