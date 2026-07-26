<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'reference_number',
    'description',
    'reason',
    'created_by',
    'hold_start_date',
    'hold_end_date',
    'is_active',
])]
class LegalHold extends Model
{
    use HasFactory;

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'hold_start_date' => 'date',
            'hold_end_date' => 'date',
            'is_active' => 'boolean',
        ];
    }
}
