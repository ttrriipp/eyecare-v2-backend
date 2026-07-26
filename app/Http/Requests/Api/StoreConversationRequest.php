<?php

namespace App\Http\Requests\Api;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreConversationRequest extends FormRequest
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

        return [
            'subject' => ['nullable', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:5000'],
            'appointment_id' => [
                'nullable',
                'integer',
                "exists:appointments,id,patient_id,{$patientId}",
            ],
            'order_id' => [
                'nullable',
                'integer',
                "exists:job_orders,id",
            ],
        ];
    }
}
