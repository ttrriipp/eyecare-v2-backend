<?php

namespace App\Http\Requests\Api;

use App\Models\AppointmentType;
use Closure;
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
            'appointment_type_id' => [
                'required',
                'integer',
                'exists:appointment_types,id',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $type = AppointmentType::query()->find($value);
                    if ($type === null || ! $type->is_active || ! $type->is_patient_visible) {
                        $fail('The selected appointment type is invalid.');
                    }
                },
            ],
            'scheduled_at' => ['required', 'date_format:Y-m-d\TH:i:sP', 'after:now'],
            'alternative_scheduled_times' => ['nullable', 'array', 'max:2'],
            'alternative_scheduled_times.*' => ['date_format:Y-m-d\TH:i:sP', 'after:now', 'distinct'],
            'reason_for_visit' => ['required', 'string', 'max:1000'],
            'referring_source' => [
                'nullable',
                'string',
                'max:255',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $typeId = $this->input('appointment_type_id');
                    if ($typeId !== null) {
                        $type = AppointmentType::query()->find($typeId);
                        if ($type !== null && $type->requires_referral && empty($value)) {
                            $fail('Referring source is required for this appointment type.');
                        }
                    }
                },
            ],
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
