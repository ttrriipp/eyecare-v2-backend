<?php

namespace App\Actions\Appointments;

use App\Enums\AppointmentRequestStatus;
use App\Models\AppointmentRequest;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class CancelAppointmentRequest
{
    public function handle(AppointmentRequest $request, User $account): AppointmentRequest
    {
        if ($request->user_id !== $account->id) {
            abort(404);
        }

        if ($request->status !== AppointmentRequestStatus::Pending) {
            throw ValidationException::withMessages([
                'request' => ['Only pending appointment requests can be cancelled.'],
            ]);
        }

        $request->update(['status' => 'cancelled']);

        return $request->fresh();
    }
}
