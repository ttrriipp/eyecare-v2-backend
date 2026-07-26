<?php

namespace App\Http\Requests\Api;

use App\Models\AppointmentStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFeedbackRequest extends FormRequest
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
        $patientId = $this->user()?->patient?->id;

        $completedAppointmentStatusId = AppointmentStatus::query()
            ->where('name', 'completed')
            ->value('id');

        return [
            'appointment_id' => [
                'required',
                'integer',
                Rule::exists('appointments', 'id')
                    ->where('patient_id', $patientId)
                    ->where('appointment_status_id', $completedAppointmentStatusId),
            ],
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
