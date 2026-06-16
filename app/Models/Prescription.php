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
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'od_sphere' => 'decimal:2',
            'od_cylinder' => 'decimal:2',
            'od_axis' => 'integer',
            'od_add' => 'decimal:2',
            'od_prism' => 'decimal:2',
            'os_sphere' => 'decimal:2',
            'os_cylinder' => 'decimal:2',
            'os_axis' => 'integer',
            'os_add' => 'decimal:2',
            'os_prism' => 'decimal:2',
            'pd' => 'decimal:2',
            'prescribed_at' => 'date',
            'expires_at' => 'date',
        ];
    }
}
