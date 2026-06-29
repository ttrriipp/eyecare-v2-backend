<?php

namespace App\Models;

use Database\Factories\PrescriptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'customer_id',
    'appointment_id',
    'previous_prescription_id',
    'created_by',
    'od_sphere',
    'od_cylinder',
    'od_axis',
    'od_add',
    'od_prism',
    'od_base',
    'os_sphere',
    'os_cylinder',
    'os_axis',
    'os_add',
    'os_prism',
    'os_base',
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
     * @return BelongsTo<User, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
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
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Sensitive health data is encrypted at rest (DPA compliance).
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'od_sphere' => 'encrypted',
            'od_cylinder' => 'encrypted',
            'od_axis' => 'encrypted',
            'od_add' => 'encrypted',
            'od_prism' => 'encrypted',
            'od_base' => 'encrypted',
            'os_sphere' => 'encrypted',
            'os_cylinder' => 'encrypted',
            'os_axis' => 'encrypted',
            'os_add' => 'encrypted',
            'os_prism' => 'encrypted',
            'os_base' => 'encrypted',
            'pd' => 'encrypted',
            'notes' => 'encrypted',
            'prescribed_at' => 'date',
            'expires_at' => 'date',
            'last_expiry_notified_at' => 'datetime',
        ];
    }
}
