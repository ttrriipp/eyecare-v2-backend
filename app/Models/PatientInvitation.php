<?php

namespace App\Models;

use App\Enums\PatientInvitationStatus;
use Database\Factories\PatientInvitationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class PatientInvitation extends Model
{
    /** @use HasFactory<PatientInvitationFactory> */
    use HasFactory;

    protected $fillable = [
        'public_id',
        'patient_id',
        'sender_id',
        'channel',
        'encrypted_destination',
        'destination_hash',
        'secret_digest',
        'status',
        'expires_at',
        'sent_at',
        'revoked_at',
        'accepted_at',
        'accepted_by_user_id',
        'failed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => PatientInvitationStatus::class,
            'encrypted_destination' => 'encrypted',
            'expires_at' => 'datetime',
            'sent_at' => 'datetime',
            'revoked_at' => 'datetime',
            'accepted_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (PatientInvitation $invitation): void {
            if (blank($invitation->public_id)) {
                $invitation->public_id = (string) Str::uuid();
            }
        });
    }

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
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function acceptedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_by_user_id');
    }

    public function isPending(): bool
    {
        return $this->status === PatientInvitationStatus::Pending
            && ! $this->isExpired();
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function revoke(): void
    {
        $this->update([
            'status' => PatientInvitationStatus::Revoked,
            'revoked_at' => now(),
        ]);
    }

    public function accept(User $user): void
    {
        $this->update([
            'status' => PatientInvitationStatus::Accepted,
            'accepted_at' => now(),
            'accepted_by_user_id' => $user->id,
        ]);
    }
}
