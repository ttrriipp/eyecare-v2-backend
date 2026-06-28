<?php

namespace App\Http\Requests\Api;

use App\Models\Appointment;
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
            if (! $scheduledAt) {
                return;
            }

            $date = Carbon::parse($scheduledAt);
            $windowStart = $date->copy()->subMinutes(30);
            $windowEnd = $date->copy()->addMinutes(30);

            $conflict = Appointment::query()
                ->whereHas('status', fn ($q) => $q->whereNotIn('name', ['cancelled']))
                ->whereBetween('scheduled_at', [$windowStart, $windowEnd])
                ->exists();

            if ($conflict) {
                $validator->errors()->add(
                    'scheduled_at',
                    'This time slot is not available. Please choose another time.'
                );
            }
        });
    }
}
