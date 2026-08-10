<?php

namespace App\Models;

use Database\Factories\LensOptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'description', 'price', 'is_active'])]
class LensOption extends Model
{
    /** @use HasFactory<LensOptionFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<QuotationItem, $this>
     */
    public function quotationItems(): HasMany
    {
        return $this->hasMany(QuotationItem::class);
    }

    /**
     * @return HasMany<JobOrderItem, $this>
     */
    public function jobOrderItems(): HasMany
    {
        return $this->hasMany(JobOrderItem::class);
    }

    /**
     * @param  Builder<LensOption>  $query
     * @return Builder<LensOption>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
