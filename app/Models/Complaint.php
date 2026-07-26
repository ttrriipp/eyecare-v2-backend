<?php

namespace App\Models;

use App\Enums\ComplaintStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'patient_id',
    'original_job_order_id',
    'original_dispensing_event_id',
    'status',
    'patient_description',
    'resolution_notes',
    'new_appointment_id',
    'new_encounter_id',
    'created_by',
    'complaint_date',
])]
class Complaint extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * @return BelongsTo<Patient, $this>
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /**
     * @return BelongsTo<JobOrder, $this>
     */
    public function originalJobOrder(): BelongsTo
    {
        return $this->belongsTo(JobOrder::class, 'original_job_order_id');
    }

    /**
     * @return BelongsTo<DispensingEvent, $this>
     */
    public function originalDispensingEvent(): BelongsTo
    {
        return $this->belongsTo(DispensingEvent::class, 'original_dispensing_event_id');
    }

    /**
     * @return BelongsTo<Appointment, $this>
     */
    public function newAppointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class, 'new_appointment_id');
    }

    /**
     * @return BelongsTo<Encounter, $this>
     */
    public function newEncounter(): BelongsTo
    {
        return $this->belongsTo(Encounter::class, 'new_encounter_id');
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
            'status' => ComplaintStatus::class,
            'complaint_date' => 'date',
        ];
    }
}
