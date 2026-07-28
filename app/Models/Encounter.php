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
    'patient_intake_id',
    'optometrist_id',
    'status',
    'started_at',
    'completed_at',
    'findings',
    'remarks',
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
     * @return BelongsTo<PatientIntake, $this>
     */
    public function intake(): BelongsTo
    {
        return $this->belongsTo(PatientIntake::class, 'patient_intake_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function optometrist(): BelongsTo
    {
        return $this->belongsTo(User::class, 'optometrist_id');
    }

    /**
     * @return HasMany<Prescription, $this>
     */
    public function prescriptions(): HasMany
    {
        return $this->hasMany(Prescription::class);
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
            'findings' => 'encrypted',
            'remarks' => 'encrypted',
        ];
    }
}
