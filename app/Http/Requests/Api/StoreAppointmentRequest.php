<?php

namespace App\Http\Requests\Api;

use App\Models\AppointmentType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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
            'appointment_type_id' => ['required', 'integer', Rule::exists('appointment_types', 'id')],
            'scheduled_at' => ['required', 'date', 'after:now'],
            'contact_notes' => ['nullable', 'string', 'max:1000'],
            'referring_source' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->has('appointment_type_id')) {
                return;
            }

            $appointmentType = AppointmentType::query()->find($this->integer('appointment_type_id'));

            if ($appointmentType !== null && $appointmentType->requires_referral && ! $this->filled('referring_source')) {
                $validator->errors()->add('referring_source', 'The referring source is required for referral appointments.');
            }
        });
    }
}
