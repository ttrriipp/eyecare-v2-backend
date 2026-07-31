<?php

namespace App\Models;

use Database\Factories\PatientAccountContactFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PatientAccountContact extends Model
{
    /** @use HasFactory<PatientAccountContactFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'type',
        'encrypted_value',
        'lookup_hash',
        'verified_at',
        'is_primary',
    ];

    protected function casts(): array
    {
        return [
            'encrypted_value' => 'encrypted',
            'verified_at' => 'datetime',
            'is_primary' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }

    public function markPrimary(): void
    {
        $this->update(['is_primary' => true]);
    }
}
