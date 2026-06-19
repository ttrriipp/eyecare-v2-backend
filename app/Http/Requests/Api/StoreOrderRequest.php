<?php

namespace App\Http\Requests\Api;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOrderRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->role->name === 'customer';
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'appointment_id' => [
                'nullable',
                'integer',
                Rule::exists('appointments', 'id')->where(
                    fn ($query) => $query->where('customer_id', $this->user()->id),
                ),
            ],
            'is_non_prescription' => ['required', 'boolean'],
            'items' => ['required', 'array', 'min:1', 'max:20'],
            'items.*.product_variant_id' => [
                'required',
                'integer',
                Rule::exists('product_variants', 'id')
                    ->where('is_active', true)
                    ->whereIn('product_id', function ($query): void {
                        $query->select('id')
                            ->from('products')
                            ->where('is_active', true);
                    }),
            ],
            'items.*.lens_type_id' => ['nullable', 'integer', Rule::exists('lens_types', 'id')],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:99'],
        ];
    }
}
