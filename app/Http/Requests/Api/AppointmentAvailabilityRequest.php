<?php

namespace App\Http\Requests\Api;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AppointmentAvailabilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role->name === 'customer';
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'visit_reason_id' => ['required', 'integer', Rule::exists('visit_reasons', 'id')],
            'optometrist_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
        ];
    }
}
