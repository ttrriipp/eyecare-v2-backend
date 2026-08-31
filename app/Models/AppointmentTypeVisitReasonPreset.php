<?php

namespace App\Models;

use Database\Factories\AppointmentTypeVisitReasonPresetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'appointment_type_id',
    'label',
    'sort_order',
    'is_active',
])]
class AppointmentTypeVisitReasonPreset extends Model
{
    /** @use HasFactory<AppointmentTypeVisitReasonPresetFactory> */
    use HasFactory;

    protected $attributes = [
        'sort_order' => 0,
        'is_active' => true,
    ];

    /**
     * @return BelongsTo<AppointmentType, $this>
     */
    public function appointmentType(): BelongsTo
    {
        return $this->belongsTo(AppointmentType::class);
    }

    /**
     * Scope to presets visible in the patient appointment-type catalog.
     *
     * @param  Builder<AppointmentTypeVisitReasonPreset>  $query
     * @return Builder<AppointmentTypeVisitReasonPreset>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
