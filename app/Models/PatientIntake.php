<?php

namespace App\Models;

use App\Enums\IntakeStatus;
use Database\Factories\PatientIntakeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'patient_id',
    'appointment_id',
    'status',
    'appointment_type',
    'full_name',
    'date_of_birth',
    'gender',
    'occupation',
    'address',
    'phone',
    'email',
    'chief_complaint',
    'past_ocular_history',
    'past_surgical_history',
    'past_medical_history',
    'allergies',
    'medications',
    'submitted_by',
    'submitted_at',
    'verified_by',
    'verified_at',
])]
class PatientIntake extends Model
{
    /** @use HasFactory<PatientIntakeFactory> */
    use HasFactory, SoftDeletes;

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
    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => IntakeStatus::class,
            'date_of_birth' => 'date',
            'submitted_at' => 'datetime',
            'verified_at' => 'datetime',
            // Clinical narrative fields use encrypted text storage.
            // Encrypted values are not queryable — no full-text search.
            'chief_complaint' => 'encrypted',
            'past_ocular_history' => 'encrypted',
            'past_surgical_history' => 'encrypted',
            'past_medical_history' => 'encrypted',
            'allergies' => 'encrypted',
            'medications' => 'encrypted',
        ];
    }
}
