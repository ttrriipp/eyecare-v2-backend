<?php

namespace App\Http\Requests\Api;

use App\Actions\Auth\AuthenticatePilotParticipant;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\Attributes\FailOnUnknownFields;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

#[FailOnUnknownFields]
class PilotParticipantLoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(AuthenticatePilotParticipant::class)->isAvailableAt();
    }

    protected function failedAuthorization()
    {
        throw new NotFoundHttpException;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'participant_code' => ['required', 'string', 'max:64'],
            'password' => ['required', 'string', 'max:255'],
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
