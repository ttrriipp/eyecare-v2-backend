<?php

namespace App\Models;

use App\Enums\EncounterStatus;
use Database\Factories\EncounterFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'encounter_number',
    'patient_id',
    'appointment_id',
    'optometrist_id',
    'status',
    'started_at',
    'completed_at',
    'findings',
    'remarks',
    'chief_complaint',
    'past_ocular_history',
    'past_surgical_history',
    'past_medical_history',
    'allergies',
    'medications',
    'plan',
    'assessment',
    'supporting_test_results',
    'last_wizard_step',
    'draft_saved_at',
    'prescription_draft',
    'completed_by',
    'voided_by',
    'voided_at',
    'void_reason',
])]
class Encounter extends Model
{
    /** @use HasFactory<EncounterFactory> */
    use HasFactory, SoftDeletes;

    protected static function booted(): void
    {
        static::creating(function (Encounter $encounter): void {
            if (blank($encounter->encounter_number)) {
                $encounter->encounter_number = self::generateEncounterNumber();
            }
        });
    }

    public static function generateEncounterNumber(): string
    {
        $year = now()->format('Y');
        $sequence = self::query()
            ->whereYear('created_at', $year)
            ->withTrashed()
            ->count() + 1;

        return sprintf('ENC-%s-%06d', $year, $sequence);
    }

    /**
     * @return BelongsTo<Patient, $this>
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /**
     * @return BelongsTo<Appointment, $this>
     */
    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function optometrist(): BelongsTo
    {
        return $this->belongsTo(User::class, 'optometrist_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    /**
     * @return HasMany<Prescription, $this>
     */
    public function prescriptions(): HasMany
    {
        return $this->hasMany(Prescription::class);
    }

    /**
     * @return HasMany<EncounterAddendum, $this>
     */
    public function addenda(): HasMany
    {
        return $this->hasMany(EncounterAddendum::class)->orderBy('sequence_number');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function isVoided(): bool
    {
        return $this->status === EncounterStatus::Voided;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => EncounterStatus::class,
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'voided_at' => 'datetime',
            'findings' => 'encrypted',
            'remarks' => 'encrypted',
            'chief_complaint' => 'encrypted',
            'past_ocular_history' => 'encrypted',
            'past_surgical_history' => 'encrypted',
            'past_medical_history' => 'encrypted',
            'allergies' => 'encrypted',
            'medications' => 'encrypted',
            'plan' => 'encrypted',
            'assessment' => 'encrypted',
            'supporting_test_results' => 'encrypted',
            'draft_saved_at' => 'datetime',
            'prescription_draft' => 'array',
        ];
    }
}
