<?php

namespace App\Http\Requests\Api;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreAppointmentRequest extends FormRequest
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
            'scheduled_at' => ['required', 'date_format:Y-m-d\TH:i:sP', 'after:now'],
            'reason_for_visit' => ['required', 'string', 'max:1000'],
            'identity' => ['nullable', 'array'],
            'identity.first_name' => ['required_with:identity', 'string', 'max:255'],
            'identity.middle_name' => ['nullable', 'string', 'max:255'],
            'identity.last_name' => ['required_with:identity', 'string', 'max:255'],
            'identity.date_of_birth' => ['required_with:identity', 'date', 'before:today'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'identity.first_name.required_with' => 'First name is required when providing identity.',
            'identity.last_name.required_with' => 'Last name is required when providing identity.',
            'identity.date_of_birth.required_with' => 'Date of birth is required when providing identity.',
            'identity.date_of_birth.before' => 'Date of birth must be in the past.',
        ];
    }
}
