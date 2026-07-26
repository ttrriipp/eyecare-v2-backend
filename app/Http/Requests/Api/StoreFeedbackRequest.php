<?php

namespace App\Http\Requests\Api;

use App\Models\AppointmentStatus;
use App\Models\OrderStatus;
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
        $userId = $this->user()?->id;
        $patientId = $this->user()?->patient?->id;

        $completedAppointmentStatusId = AppointmentStatus::query()
            ->where('name', 'completed')
            ->value('id');

        $completedOrderStatusId = OrderStatus::query()
            ->where('name', 'completed')
            ->value('id');

        return [
            'appointment_id' => [
                Rule::requiredIf(fn () => ! $this->filled('order_id')),
                'nullable',
                'integer',
                Rule::exists('appointments', 'id')
                    ->where('patient_id', $patientId)
                    ->where('appointment_status_id', $completedAppointmentStatusId),
            ],
            'order_id' => [
                'nullable',
                'integer',
                Rule::exists('job_orders', 'id'),
            ],
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
