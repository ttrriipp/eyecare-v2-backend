<?php

namespace App\Http\Requests\Api;

use App\Models\Appointment;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class CancelPatientAppointmentRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $user = $this->user();
        $appointment = $this->route('appointment');

        return $user?->isPatient() === true
            && $appointment instanceof Appointment
            && $appointment->patient_id === $user->patient?->id;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'reason_details' => ['required', 'string', 'max:1000'],
        ];
    }
}
