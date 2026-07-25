<?php

namespace App\Http\Requests\Api;

use App\Models\Appointment;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StorePatientIntakeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'appointment_id' => ['nullable', 'integer', 'exists:appointments,id'],
            'appointment_type' => ['nullable', 'string', 'max:255'],
            'full_name' => ['sometimes', 'string', 'max:255'],
            'date_of_birth' => ['sometimes', 'nullable', 'date', 'before:today'],
            'gender' => ['sometimes', 'nullable', 'string', 'in:male,female,other'],
            'occupation' => ['sometimes', 'nullable', 'string', 'max:255'],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:20'],
            'email' => ['sometimes', 'nullable', 'string', 'email', 'max:255'],
            'chief_complaint' => ['sometimes', 'nullable', 'string'],
            'past_ocular_history' => ['sometimes', 'nullable', 'string'],
            'past_surgical_history' => ['sometimes', 'nullable', 'string'],
            'past_medical_history' => ['sometimes', 'nullable', 'string'],
            'allergies' => ['sometimes', 'nullable', 'string'],
            'medications' => ['sometimes', 'nullable', 'string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->filled('appointment_id')) {
                $appointment = Appointment::find($this->integer('appointment_id'));

                if ($appointment !== null && $appointment->patient_id !== $this->user()?->patient?->id) {
                    $validator->errors()->add('appointment_id', 'The selected appointment does not belong to you.');
                }
            }
        });
    }
}
