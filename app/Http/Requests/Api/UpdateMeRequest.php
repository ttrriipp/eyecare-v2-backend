<?php

namespace App\Http\Requests\Api;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateMeRequest extends FormRequest
{
    /**
     * @var list<string>
     */
    private const ALLOWED_FIELDS = [
        'first_name',
        'middle_name',
        'last_name',
        'date_of_birth',
    ];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'first_name' => ['sometimes', 'string', 'max:255', 'filled'],
            'middle_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'last_name' => ['sometimes', 'string', 'max:255', 'filled'],
            'date_of_birth' => ['sometimes', 'date_format:Y-m-d', 'before:today'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];

        foreach (['first_name', 'middle_name', 'last_name'] as $field) {
            if (! $this->exists($field)) {
                continue;
            }

            $value = $this->input($field);

            if (! is_string($value)) {
                continue;
            }

            $value = trim($value);
            $normalized[$field] = $field === 'middle_name' && $value === '' ? null : $value;
        }

        $this->merge($normalized);
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $submittedFields = array_keys($this->all());
            $unsupportedFields = array_diff($submittedFields, self::ALLOWED_FIELDS);

            foreach ($unsupportedFields as $field) {
                $validator->errors()->add($field, 'This field is not editable through this endpoint.');
            }

            if ($submittedFields === []) {
                $validator->errors()->add('profile', 'At least one editable field is required.');
            }
        }];
    }
}
