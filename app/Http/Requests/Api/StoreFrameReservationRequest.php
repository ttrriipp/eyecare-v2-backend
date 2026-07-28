<?php

namespace App\Http\Requests\Api;

use App\Models\Appointment;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreFrameReservationRequest extends FormRequest
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
            'appointment_id' => ['required', 'integer', 'exists:appointments,id'],
            'items' => ['required', 'array', 'min:1', 'max:5'],
            'items.*.product_variant_id' => ['required', 'integer', 'exists:product_variants,id'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->filled('appointment_id')) {
                $appointment = Appointment::find($this->integer('appointment_id'));
                $patient = $this->user()?->patient;

                if ($appointment !== null && $patient !== null && $appointment->patient_id !== $patient->id) {
                    $validator->errors()->add('appointment_id', 'The selected appointment does not belong to you.');
                }
            }
        });
    }
}
