<?php

namespace App\Models;

use Database\Factories\LensCategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['name', 'description', 'price', 'is_active'])]
class LensCategory extends Model
{
    /** @use HasFactory<LensCategoryFactory> */
    use HasFactory, SoftDeletes;

    /**
     * @param  Builder<LensCategory>  $query
     * @return Builder<LensCategory>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }
}
