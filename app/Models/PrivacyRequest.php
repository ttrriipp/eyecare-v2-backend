<?php

namespace App\Models;

use App\Enums\PrivacyRequestDisposition;
use App\Enums\PrivacyRequestType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'patient_id',
    'requester_user_id',
    'request_type',
    'identity_verified_method',
    'description',
    'disposition',
    'disposition_reason',
    'handled_by',
    'requested_at',
    'handled_at',
])]
class PrivacyRequest extends Model
{
    use HasFactory;

    /**
     * @return BelongsTo<Patient, $this>
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function handledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'request_type' => PrivacyRequestType::class,
            'disposition' => PrivacyRequestDisposition::class,
            'requested_at' => 'datetime',
            'handled_at' => 'datetime',
        ];
    }
}
