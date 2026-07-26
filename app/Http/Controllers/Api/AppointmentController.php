<?php

namespace App\Http\Controllers\Api;

use App\Actions\Appointments\CreateScheduledAppointment;
use App\Actions\Appointments\RescheduleAppointment;
use App\Actions\Appointments\UpdateAppointmentContactNote;
use App\Actions\Appointments\UpdateAppointmentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\RescheduleAppointmentRequest;
use App\Http\Requests\Api\StoreAppointmentRequest;
use App\Http\Requests\Api\UpdateAppointmentContactNoteRequest;
use App\Http\Resources\AppointmentResource;
use App\Models\Appointment;
use App\Models\User;
use App\Models\VisitReason;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class AppointmentController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $patient = $request->user()->patient;

        abort_unless($patient !== null, 404);

        $appointments = Appointment::query()
            ->where('patient_id', $patient->id)
            ->with(['visitReason', 'status', 'optometrist'])
            ->latest('scheduled_at')
            ->paginate($request->integer('per_page', 15));

        return AppointmentResource::collection($appointments);
    }

    public function store(StoreAppointmentRequest $request, CreateScheduledAppointment $createScheduledAppointment): JsonResponse
    {
        $patient = $request->user()->patient;

        abort_unless($patient !== null, 422, 'No patient record linked to this account.');

        $visitReason = VisitReason::query()->findOrFail($request->validated('visit_reason_id'));
        $scheduledAt = Carbon::parse($request->validated('scheduled_at'))->setTimezone(config('app.timezone'));

        $appointment = $createScheduledAppointment->handle(
            patient: $patient,
            visitReason: $visitReason,
            scheduledAt: $scheduledAt,
            optometrist: null, // Clinic-controlled assignment
            contactNotes: $request->validated('contact_notes'),
        );

        $staff = User::query()
            ->whereHas('role', fn ($q) => $q->whereIn('name', ['staff', 'admin']))
            ->get();

        Notification::make()
            ->title('New Appointment Booked')
            ->body("{$appointment->patient->full_name} booked appointment {$appointment->appointment_number} on {$appointment->scheduled_at->format('M d, Y g:i A')}.")
            ->actions([
                Action::make('view')
                    ->label('View')
                    ->url('/admin/appointments/'.$appointment->id.'/edit')
                    ->markAsRead(),
            ])
            ->sendToDatabase($staff);

        return response()->json([
            'data' => AppointmentResource::make($appointment),
        ], 201);
    }

    public function show(Request $request, Appointment $appointment): JsonResponse
    {
        $patient = $request->user()->patient;

        abort_unless($patient !== null && $appointment->patient_id === $patient->id, 404);

        $appointment->load(['visitReason', 'status', 'optometrist']);

        return response()->json([
            'data' => AppointmentResource::make($appointment),
        ]);
    }

    public function cancel(Request $request, Appointment $appointment): JsonResponse
    {
        $patient = $request->user()->patient;

        abort_unless($patient !== null && $appointment->patient_id === $patient->id, 403);

        if (! in_array($appointment->status->name, ['pending', 'confirmed'], true)) {
            throw ValidationException::withMessages([
                'appointment' => ['This appointment cannot be cancelled.'],
            ]);
        }

        app(UpdateAppointmentStatus::class)->handle($appointment, 'cancelled');

        $appointment->load(['visitReason', 'status', 'patient']);

        $staff = User::query()
            ->whereHas('role', fn ($q) => $q->whereIn('name', ['staff', 'admin']))
            ->get();

        Notification::make()
            ->title('Appointment Cancelled by Patient')
            ->body("{$appointment->patient->full_name} cancelled appointment {$appointment->appointment_number} on {$appointment->scheduled_at->format('M d, Y g:i A')}.")
            ->warning()
            ->sendToDatabase($staff);

        return response()->json([
            'data' => AppointmentResource::make($appointment),
        ]);
    }

    public function updateContactNote(
        UpdateAppointmentContactNoteRequest $request,
        Appointment $appointment,
        UpdateAppointmentContactNote $updateAppointmentContactNote,
    ): JsonResponse {
        $appointment = $updateAppointmentContactNote->handle(
            appointment: $appointment,
            contactNotes: $request->validated('contact_notes'),
        );

        return response()->json([
            'data' => AppointmentResource::make($appointment),
        ]);
    }

    public function reschedule(
        RescheduleAppointmentRequest $request,
        Appointment $appointment,
        RescheduleAppointment $rescheduleAppointment,
    ): JsonResponse {
        $previousScheduledAt = $appointment->scheduled_at->format('M d, Y g:i A');

        $appointment = $rescheduleAppointment->handle(
            appointment: $appointment,
            scheduledAt: Carbon::parse($request->validated('scheduled_at'))->setTimezone(config('app.timezone')),
            customerInitiated: true,
        );

        $staff = User::query()
            ->whereHas('role', fn ($q) => $q->whereIn('name', ['staff', 'admin']))
            ->get();

        Notification::make()
            ->title('Appointment Rescheduled by Patient')
            ->body("{$appointment->patient->full_name} rescheduled appointment {$appointment->appointment_number} from {$previousScheduledAt} to {$appointment->scheduled_at->format('M d, Y g:i A')}.")
            ->actions([
                Action::make('view')
                    ->label('View')
                    ->url('/admin/appointments/'.$appointment->id.'/edit')
                    ->markAsRead(),
            ])
            ->warning()
            ->sendToDatabase($staff);

        return response()->json([
            'data' => AppointmentResource::make($appointment),
        ]);
    }
}
