<?php

namespace App\Http\Requests\Api;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreConversationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $userId = $this->user()?->id;

        return [
            'subject' => ['nullable', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:5000'],
            'appointment_id' => [
                'nullable',
                'integer',
                "exists:appointments,id,customer_id,{$userId}",
            ],
            'order_id' => [
                'nullable',
                'integer',
                "exists:orders,id,customer_id,{$userId}",
            ],
        ];
    }
}
