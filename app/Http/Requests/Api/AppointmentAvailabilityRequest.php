<?php

namespace App\Http\Requests\Api;

use App\Models\Appointment;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class AppointmentAvailabilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role->name === 'patient';
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'visit_reason_id' => ['required', 'integer', Rule::exists('visit_reasons', 'id')],
            'appointment_id' => ['nullable', 'integer', Rule::exists('appointments', 'id')->where(
                fn ($query) => $query->where('patient_id', $this->user()?->patient?->id),
            )],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->validateRescheduleContext($validator);
        });
    }

    private function validateRescheduleContext(Validator $validator): void
    {
        if (! $this->filled('appointment_id') || $validator->errors()->has('appointment_id')) {
            return;
        }

        $appointment = Appointment::query()
            ->with(['status:id,name'])
            ->where('patient_id', $this->user()?->patient?->id)
            ->find($this->integer('appointment_id'));

        if ($appointment === null) {
            return;
        }

        if (! in_array($appointment->status?->name, ['pending', 'confirmed'], true)) {
            $validator->errors()->add('appointment_id', 'This appointment cannot be rescheduled.');
        }

        if ((int) $this->input('visit_reason_id') !== $appointment->visit_reason_id) {
            $validator->errors()->add('visit_reason_id', 'The visit reason must match the appointment being rescheduled.');
        }
    }
}
