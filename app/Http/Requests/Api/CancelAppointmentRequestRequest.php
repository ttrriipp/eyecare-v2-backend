<?php

namespace App\Http\Requests\Api;

use App\Models\AppointmentRequest;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class CancelAppointmentRequestRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $user = $this->user();
        $appointmentRequest = $this->route('appointmentRequest');

        abort_unless(
            $appointmentRequest instanceof AppointmentRequest
                && $appointmentRequest->user_id === $user?->id,
            404,
        );

        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'reason_details' => ['required', 'string', 'max:1000'],
        ];
    }
}
