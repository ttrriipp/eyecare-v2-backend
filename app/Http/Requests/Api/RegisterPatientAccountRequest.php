<?php

namespace App\Http\Requests\Api;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class RegisterPatientAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'registration_token' => ['required', 'string'],
            'first_name' => ['required', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'date_of_birth' => ['required', 'date', 'before:today'],
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:12', 'confirmed'],
            'password_confirmation' => ['required', 'string'],
            'privacy_policy_version' => ['required', 'string'],
            'terms_version' => ['required', 'string'],
            'invitation_code' => ['nullable', 'string'],
            'device_name' => ['nullable', 'string', 'max:255'],
            'installation_id' => ['nullable', 'string', 'max:255'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'message' => 'The given data was invalid.',
            'errors' => $validator->errors(),
        ], 422));
    }
}
