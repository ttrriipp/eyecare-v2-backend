<?php

namespace App\Models;

use App\Enums\QuotationStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

#[Fillable([
    'quotation_number',
    'patient_id',
    'encounter_id',
    'prescription_id',
    'status',
    'valid_until',
    'notes',
    'internal_notes',
])]
class Quotation extends Model
{
    use HasFactory, SoftDeletes;

    protected static function booted(): void
    {
        static::creating(function (Quotation $quotation): void {
            if (blank($quotation->quotation_number)) {
                $quotation->quotation_number = 'QUO-'.Str::ulid();
            }
        });
    }

    /**
     * @return BelongsTo<Patient, $this>
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /**
     * @return BelongsTo<Encounter, $this>
     */
    public function encounter(): BelongsTo
    {
        return $this->belongsTo(Encounter::class);
    }

    /**
     * @return HasMany<QuotationRevision, $this>
     */
    public function revisions(): HasMany
    {
        return $this->hasMany(QuotationRevision::class);
    }

    /**
     * Get the latest revision.
     */
    public function latestRevision()
    {
        return $this->hasOne(QuotationRevision::class)->latestOfMany('revision_number');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => QuotationStatus::class,
            'valid_until' => 'date',
        ];
    }
}
