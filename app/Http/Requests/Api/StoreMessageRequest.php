<?php

namespace App\Http\Requests\Api;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;

class StoreMessageRequest extends FormRequest
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
            'body' => ['required', 'string', 'max:5000'],
            'attachment' => [
                'nullable',
                File::types(['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx'])
                    ->max('10mb'),
            ],
            'contexts' => ['nullable', 'array', 'max:5'],
            'contexts.*.type' => ['required', 'string', Rule::in(['appointment', 'order', 'product'])],
            'contexts.*.id' => ['required', 'integer'],
        ];
    }
}
