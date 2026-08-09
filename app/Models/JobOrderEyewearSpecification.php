<?php

namespace App\Models;

use App\Enums\FrameSource;
use Database\Factories\JobOrderEyewearSpecificationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'job_order_id',
    'prescription_id',
    'frame_job_order_item_id',
    'lens_package_job_order_item_id',
    'frame_source',
    'lens_design_snapshot',
    'lens_material_snapshot',
    'refractive_index_snapshot',
    'lens_options_snapshot',
    'distance_pd_mode',
    'distance_pd_binocular',
    'distance_pd_od',
    'distance_pd_os',
    'near_pd_binocular',
    'near_pd_od',
    'near_pd_os',
    'fitting_height_od',
    'fitting_height_os',
    'segment_height_od',
    'segment_height_os',
    'lab_instructions',
    'approved_by',
    'approved_at',
    'verified_by',
    'verified_at',
    'verification_notes',
])]
class JobOrderEyewearSpecification extends Model
{
    /** @use HasFactory<JobOrderEyewearSpecificationFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'frame_source' => FrameSource::class,
            'lens_options_snapshot' => 'array',
            'approved_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<JobOrder, $this>
     */
    public function jobOrder(): BelongsTo
    {
        return $this->belongsTo(JobOrder::class);
    }

    /**
     * @return BelongsTo<Prescription, $this>
     */
    public function prescription(): BelongsTo
    {
        return $this->belongsTo(Prescription::class);
    }

    /**
     * @return BelongsTo<JobOrderItem, $this>
     */
    public function frameItem(): BelongsTo
    {
        return $this->belongsTo(JobOrderItem::class, 'frame_job_order_item_id');
    }

    /**
     * @return BelongsTo<JobOrderItem, $this>
     */
    public function lensPackageItem(): BelongsTo
    {
        return $this->belongsTo(JobOrderItem::class, 'lens_package_job_order_item_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function isApproved(): bool
    {
        return $this->approved_at !== null;
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }
}
