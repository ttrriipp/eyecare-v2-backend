<?php

namespace App\Http\Controllers\Api;

use App\Actions\Appointments\UpdateAppointmentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreAppointmentRequest;
use App\Http\Resources\AppointmentResource;
use App\Models\Appointment;
use App\Models\AppointmentStatus;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class AppointmentController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $appointments = Appointment::query()
            ->where('customer_id', $request->user()->id)
            ->with(['visitReason', 'status'])
            ->latest('scheduled_at')
            ->get();

        return AppointmentResource::collection($appointments);
    }

    public function store(StoreAppointmentRequest $request): JsonResponse
    {
        $pendingStatus = AppointmentStatus::query()->where('name', 'pending')->firstOrFail();

        $appointment = Appointment::query()->create([
            ...$request->validated(),
            'customer_id' => $request->user()->id,
            'appointment_status_id' => $pendingStatus->id,
        ]);

        $appointment->load(['visitReason', 'status', 'customer']);

        $staff = User::query()
            ->whereHas('role', fn ($q) => $q->whereIn('name', ['staff', 'admin']))
            ->get();

        Notification::make()
            ->title('New Appointment Booked')
            ->body("{$appointment->customer->name} booked an appointment on {$appointment->scheduled_at->format('M d, Y g:i A')}.")
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
        abort_unless($appointment->customer_id === $request->user()->id, 404);

        $appointment->load(['visitReason', 'status']);

        return response()->json([
            'data' => AppointmentResource::make($appointment),
        ]);
    }

    public function cancel(Request $request, Appointment $appointment): JsonResponse
    {
        abort_unless($appointment->customer_id === $request->user()->id, 403);

        if (! in_array($appointment->status->name, ['pending', 'confirmed'], true)) {
            throw ValidationException::withMessages([
                'appointment' => ['This appointment cannot be cancelled.'],
            ]);
        }

        app(UpdateAppointmentStatus::class)->handle($appointment, 'cancelled');

        $appointment->load(['visitReason', 'status']);

        return response()->json([
            'data' => AppointmentResource::make($appointment),
        ]);
    }
}
