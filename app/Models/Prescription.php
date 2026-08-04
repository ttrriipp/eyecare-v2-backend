<?php

namespace App\Models;

use Database\Factories\PrescriptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'prescription_number',
    'patient_id',
    'encounter_id',
    'appointment_id',
    'previous_prescription_id',
    'amendment_reason',
    'created_by',
    'main_od_value',
    'main_od_sphere',
    'main_od_cylinder',
    'main_os_value',
    'main_os_sphere',
    'main_os_cylinder',
    'add_od_value',
    'add_od_sphere',
    'add_od_cylinder',
    'add_os_value',
    'add_os_sphere',
    'add_os_cylinder',
    'remarks',
    'prescribed_at',
])]
class Prescription extends Model
{
    /** @use HasFactory<PrescriptionFactory> */
    use HasFactory, SoftDeletes;

    protected static function booted(): void
    {
        static::creating(function (Prescription $prescription): void {
            if (blank($prescription->prescription_number)) {
                $year = now()->format('Y');
                $sequence = self::query()->withTrashed()->whereYear('created_at', $year)->count() + 1;
                $prescription->prescription_number = sprintf('RX-%s-%06d', $year, $sequence);
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
     * @return BelongsTo<Appointment, $this>
     */
    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    /**
     * @return BelongsTo<Prescription, $this>
     */
    public function previousPrescription(): BelongsTo
    {
        return $this->belongsTo(self::class, 'previous_prescription_id')
            ->withTrashed();
    }

    /**
     * @return HasOne<Prescription, $this>
     */
    public function nextPrescription(): HasOne
    {
        return $this->hasOne(self::class, 'previous_prescription_id')
            ->withTrashed();
    }

    public function isCurrentVersion(): bool
    {
        return ! $this->nextPrescription()->exists();
    }

    public function currentVersion(): Prescription
    {
        $currentVersion = $this;

        while ($nextVersion = $currentVersion->nextPrescription()->first()) {
            $currentVersion = $nextVersion;
        }

        return $currentVersion;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // Main group - encrypted
            'main_od_value' => 'encrypted',
            'main_od_sphere' => 'encrypted',
            'main_od_cylinder' => 'encrypted',
            'main_os_value' => 'encrypted',
            'main_os_sphere' => 'encrypted',
            'main_os_cylinder' => 'encrypted',
            // ADD group - encrypted
            'add_od_value' => 'encrypted',
            'add_od_sphere' => 'encrypted',
            'add_od_cylinder' => 'encrypted',
            'add_os_value' => 'encrypted',
            'add_os_sphere' => 'encrypted',
            'add_os_cylinder' => 'encrypted',
            // Other encrypted fields
            'remarks' => 'encrypted',
            'amendment_reason' => 'encrypted',
            // Date
            'prescribed_at' => 'date',
        ];
    }
}
