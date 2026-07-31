<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PatientLinkCandidate extends Model
{
    protected $fillable = [
        'link_request_id',
        'patient_id',
        'match_strength',
        'reason_codes',
        'rank',
    ];

    protected function casts(): array
    {
        return [
            'reason_codes' => 'array',
            'rank' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<PatientLinkRequest, $this>
     */
    public function linkRequest(): BelongsTo
    {
        return $this->belongsTo(PatientLinkRequest::class);
    }

    /**
     * @return BelongsTo<Patient, $this>
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }
}
