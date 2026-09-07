<?php

namespace App\Http\Requests\Api;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreAppointmentRescheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->patient !== null;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'requested_scheduled_at' => ['required', 'date_format:Y-m-d\TH:i:sP', 'after:now'],
            'alternative_scheduled_times' => ['nullable', 'array', 'max:2'],
            'alternative_scheduled_times.*' => [
                'date_format:Y-m-d\TH:i:sP',
                'after:now',
                'distinct',
            ],
            'reason_details' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $allowedFields = array_keys($this->rules());
            $unknownFields = array_diff(array_keys($this->all()), $allowedFields);

            foreach ($unknownFields as $field) {
                $validator->errors()->add($field, 'This field is not allowed.');
            }

            $requestedTime = $this->input('requested_scheduled_at');
            $alternatives = $this->input('alternative_scheduled_times', []);

            if (! is_string($requestedTime) || ! is_array($alternatives)) {
                return;
            }

            try {
                $requested = now()->parse($requestedTime);
                foreach ($alternatives as $index => $alternative) {
                    if (is_string($alternative) && $requested->equalTo(now()->parse($alternative))) {
                        $validator->errors()->add(
                            "alternative_scheduled_times.{$index}",
                            'Requested times must be distinct.',
                        );
                    }
                }
            } catch (\Throwable) {
                // The date format rules provide the field-level validation message.
            }
        });
    }
}
