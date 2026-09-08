<?php

namespace App\Http\Controllers\Api;

use App\Actions\Appointments\CancelAppointment;
use App\Actions\Appointments\CreateScheduledAppointment;
use App\Actions\Appointments\RescheduleAppointment;
use App\Actions\Appointments\UpdateAppointmentContactNote;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\RescheduleAppointmentRequest;
use App\Http\Requests\Api\StoreAppointmentRequest;
use App\Http\Requests\Api\UpdateAppointmentContactNoteRequest;
use App\Http\Resources\AppointmentResource;
use App\Models\Appointment;
use App\Models\AppointmentType;
use App\Models\User;
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
            ->with(['appointmentType', 'status', 'optometrist', 'latestReschedule', 'visitRating'])
            ->latest('scheduled_at')
            ->paginate($request->integer('per_page', 15));

        return AppointmentResource::collection($appointments);
    }

    public function store(StoreAppointmentRequest $request, CreateScheduledAppointment $createScheduledAppointment): JsonResponse
    {
        $patient = $request->user()->patient;

        abort_unless($patient !== null, 422, 'No patient record linked to this account.');

        $appointmentType = AppointmentType::query()->findOrFail($request->validated('appointment_type_id'));
        $scheduledAt = Carbon::parse($request->validated('scheduled_at'))->setTimezone(config('app.timezone'));

        $appointment = $createScheduledAppointment->handle(
            patient: $patient,
            appointmentType: $appointmentType,
            scheduledAt: $scheduledAt,
            optometrist: null,
            contactNotes: $request->validated('contact_notes'),
            referringSource: $request->validated('referring_source'),
            reasonForVisit: $request->validated('reason_for_visit'),
        );

        $staff = User::query()
            ->whereHas('roles', fn ($q) => $q->whereIn('name', ['staff', 'admin']))
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

        $appointment->load(['appointmentType', 'status', 'optometrist', 'latestReschedule', 'visitRating']);

        return response()->json([
            'data' => AppointmentResource::make($appointment),
        ]);
    }

    public function cancel(Request $request, Appointment $appointment): JsonResponse
    {
        $patient = $request->user()->patient;

        abort_unless($patient !== null && $appointment->patient_id === $patient->id, 403);

        try {
            app(CancelAppointment::class)->handle(
                appointment: $appointment,
                initiator: 'patient',
                actor: $request->user(),
                reasonCategory: $request->input('reason_category'),
                reasonDetails: $request->input('reason_details'),
            );
        } catch (ValidationException $e) {
            throw $e;
        }

        $appointment->load(['appointmentType', 'status', 'patient', 'latestReschedule']);

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
        $appointment = $rescheduleAppointment->handle(
            appointment: $appointment,
            scheduledAt: Carbon::parse($request->validated('scheduled_at'))->setTimezone(config('app.timezone')),
            customerInitiated: true,
            rescheduleReason: $request->input('reason_details'),
            reasonCategory: $request->input('reason_category'),
        );

        $appointment->load('latestReschedule');

        return response()->json([
            'data' => AppointmentResource::make($appointment),
        ]);
    }
}
