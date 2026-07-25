<?php

namespace App\Http\Requests\Api;

use App\Models\Product;
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
        return $this->user()?->role->name === 'patient';
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'appointment_id' => ['prohibited'],
            'is_non_prescription' => ['required', 'boolean:strict', 'accepted'],
            'items' => ['required', 'array', 'min:1', 'max:20'],
            'items.*.product_variant_id' => [
                'required',
                'integer',
                Rule::exists('product_variants', 'id')
                    ->where('is_active', true)
                    ->whereIn('product_id', function ($query): void {
                        $query->select('id')
                            ->from('products')
                            ->where('is_active', true)
                            ->whereIn('product_type', Product::CUSTOMER_ORDERABLE_TYPES);
                    }),
            ],
            'items.*.lens_category_id' => ['prohibited'],
            'items.*.lens_type_id' => ['prohibited'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:99'],
        ];
    }
}
