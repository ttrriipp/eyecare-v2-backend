<?php

namespace App\Http\Requests\Api;

use App\Models\Appointment;
use App\Models\AppointmentType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class AppointmentAvailabilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isPatient();
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'appointment_type_id' => [
                'required',
                'integer',
                Rule::exists('appointment_types', 'id')
                    ->where('is_active', true)
                    ->where('is_patient_visible', true),
            ],
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

        if (! in_array($appointment->status?->name, ['pending', 'confirmed', 'scheduled'], true)) {
            $validator->errors()->add('appointment_id', 'This appointment cannot be rescheduled.');
        }

        $appointmentType = AppointmentType::query()->find($this->integer('appointment_type_id'));

        if ($appointmentType !== null && $appointmentType->duration_minutes !== $appointment->duration_minutes) {
            $validator->errors()->add('appointment_type_id', 'The appointment type duration must match the appointment being rescheduled.');
        }
    }
}
