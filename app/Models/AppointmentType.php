<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'name',
    'patient_label',
    'patient_description',
    'duration_minutes',
    'requires_referral',
    'is_active',
    'is_patient_visible',
])]
class AppointmentType extends Model
{
    use HasFactory;

    /**
     * @return HasMany<Appointment, $this>
     */
    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    /**
     * @return HasMany<AppointmentTypeVisitReasonPreset, $this>
     */
    public function visitReasonPresets(): HasMany
    {
        return $this->hasMany(AppointmentTypeVisitReasonPreset::class)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    /**
     * @return HasMany<AppointmentTypeVisitReasonPreset, $this>
     */
    public function activeVisitReasonPresets(): HasMany
    {
        return $this->visitReasonPresets()->active();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'requires_referral' => 'boolean',
            'is_active' => 'boolean',
            'is_patient_visible' => 'boolean',
        ];
    }

    /**
     * Get the patient-facing label, falling back to the internal name.
     */
    public function getPatientLabelAttribute(): string
    {
        return $this->attributes['patient_label'] ?? $this->attributes['name'];
    }

    /**
     * Scope for types visible to patients (active and patient-visible).
     *
     * @param  Builder<AppointmentType>  $query
     * @return Builder<AppointmentType>
     */
    public function scopePatientVisible($query)
    {
        return $query->where('is_active', true)->where('is_patient_visible', true);
    }

    /**
     * Scope for active types (staff forms include internal-only types).
     *
     * @param  Builder<AppointmentType>  $query
     * @return Builder<AppointmentType>
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
