<?php

namespace App\Http\Requests\Api;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateAppointmentRequestSchedule extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isPatient() ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'scheduled_at' => ['required', 'date_format:Y-m-d\TH:i:sP', 'after:now'],
            'alternative_scheduled_times' => ['nullable', 'array', 'max:2'],
            'alternative_scheduled_times.*' => ['date_format:Y-m-d\TH:i:sP', 'after:now', 'distinct'],
        ];
    }
}
