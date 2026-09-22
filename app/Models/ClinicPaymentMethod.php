<?php

namespace App\Models;

use App\Enums\OrderPaymentMethod;
use Database\Factories\ClinicPaymentMethodFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'method',
    'label',
    'bank_name',
    'account_name',
    'account_number',
    'qr_image_path',
    'is_active',
])]
class ClinicPaymentMethod extends Model
{
    /** @use HasFactory<ClinicPaymentMethodFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'method' => OrderPaymentMethod::class,
            'is_active' => 'boolean',
        ];
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function isConfigured(): bool
    {
        if (blank($this->account_name) || blank($this->account_number)) {
            return false;
        }

        return $this->method !== OrderPaymentMethod::BankTransfer || filled($this->bank_name);
    }
}
