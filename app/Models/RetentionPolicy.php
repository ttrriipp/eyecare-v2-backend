<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'category',
    'description',
    'retention_days',
    'next_review_date',
    'auto_purge_enabled',
    'notes',
])]
class RetentionPolicy extends Model
{
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'next_review_date' => 'date',
            'auto_purge_enabled' => 'boolean',
        ];
    }
}
