<?php

namespace App\Http\Requests\Api;

use App\Models\Appointment;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Validator;

class RescheduleAppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Appointment $appointment */
        $appointment = $this->route('appointment');

        return $appointment->customer_id === $this->user()?->id;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'scheduled_at' => ['required', 'date', 'after:now'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $scheduledAt = $this->input('scheduled_at');
            if (! $scheduledAt) {
                return;
            }

            /** @var Appointment $appointment */
            $appointment = $this->route('appointment');
            $duration = $appointment->visitReason?->duration_minutes ?? 30;

            if (Appointment::conflictsWith(Carbon::parse($scheduledAt), $duration, $appointment->id)) {
                $validator->errors()->add(
                    'scheduled_at',
                    'This time slot is not available. Please choose another time.'
                );
            }
        });
    }
}
