<?php

namespace App\Models;

use Database\Factories\PrescriptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'patient_id',
    'encounter_id',
    'appointment_id',
    'previous_prescription_id',
    'created_by',
    'od_sphere',
    'od_cylinder',
    'od_axis',
    'od_add',
    'os_sphere',
    'os_cylinder',
    'os_axis',
    'os_add',
    'pd',
    'prescribed_at',
    'expires_at',
    'notes',
    'last_expiry_notified_at',
])]
class Prescription extends Model
{
    /** @use HasFactory<PrescriptionFactory> */
    use HasFactory, SoftDeletes;

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
        return $this->belongsTo(self::class, 'previous_prescription_id');
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
            'od_sphere' => 'encrypted',
            'od_cylinder' => 'encrypted',
            'od_axis' => 'encrypted',
            'od_add' => 'encrypted',
            'os_sphere' => 'encrypted',
            'os_cylinder' => 'encrypted',
            'os_axis' => 'encrypted',
            'os_add' => 'encrypted',
            'pd' => 'encrypted',
            'notes' => 'encrypted',
            'prescribed_at' => 'date',
            'expires_at' => 'date',
            'last_expiry_notified_at' => 'datetime',
        ];
    }
}
