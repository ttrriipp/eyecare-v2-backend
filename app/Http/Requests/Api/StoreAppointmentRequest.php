<?php

namespace App\Http\Requests\Api;

use App\Models\Appointment;
use App\Models\VisitReason;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreAppointmentRequest extends FormRequest
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
            'visit_reason_id' => ['required', 'integer', Rule::exists('visit_reasons', 'id')],
            'scheduled_at' => ['required', 'date', 'after:now'],
            'contact_notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $scheduledAt = $this->input('scheduled_at');
            $visitReasonId = $this->input('visit_reason_id');
            if (! $scheduledAt || ! $visitReasonId) {
                return;
            }

            $duration = VisitReason::query()->where('id', $visitReasonId)->value('duration_minutes') ?? 30;

            if (Appointment::conflictsWith(Carbon::parse($scheduledAt), $duration)) {
                $validator->errors()->add(
                    'scheduled_at',
                    'This time slot is not available. Please choose another time.'
                );
            }
        });
    }
}
