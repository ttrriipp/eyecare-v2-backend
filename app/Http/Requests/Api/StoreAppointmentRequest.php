<?php

namespace App\Http\Requests\Api;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreAppointmentRequest extends FormRequest
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
            'scheduled_at' => ['required', 'date_format:Y-m-d\TH:i:sP', 'after:now'],
            'reason_for_visit' => ['required', 'string', 'max:1000'],
            'identity' => ['nullable', 'array:phone,email,first_name,middle_name,last_name,date_of_birth,gender,occupation,address'],
            'identity.phone' => ['required_with:identity', 'string', 'max:20'],
            'identity.email' => ['nullable', 'string', 'email', 'max:255'],
            'identity.first_name' => ['required_with:identity', 'string', 'max:255'],
            'identity.middle_name' => ['nullable', 'string', 'max:255'],
            'identity.last_name' => ['required_with:identity', 'string', 'max:255'],
            'identity.date_of_birth' => ['required_with:identity', 'date', 'before:today'],
            'identity.gender' => ['required_with:identity', 'string', 'in:male,female,other'],
            'identity.occupation' => ['required_with:identity', 'string', 'max:255'],
            'identity.address' => ['required_with:identity', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'identity.phone.required_with' => 'Phone is required when providing identity.',
            'identity.first_name.required_with' => 'First name is required when providing identity.',
            'identity.last_name.required_with' => 'Last name is required when providing identity.',
            'identity.date_of_birth.required_with' => 'Date of birth is required when providing identity.',
            'identity.gender.required_with' => 'Gender is required when providing identity.',
            'identity.occupation.required_with' => 'Occupation is required when providing identity.',
            'identity.address.required_with' => 'Home address is required when providing identity.',
            'identity.date_of_birth.before' => 'Date of birth must be in the past.',
        ];
    }
}
