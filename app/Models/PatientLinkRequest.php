<?php

namespace App\Models;

use App\Enums\AuditEvent;
use Database\Factories\PatientLinkRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class PatientLinkRequest extends Model
{
    /** @use HasFactory<PatientLinkRequestFactory> */
    use HasFactory;

    protected $fillable = [
        'request_number',
        'user_id',
        'encrypted_identity_snapshot',
        'status',
        'reviewed_patient_id',
        'reviewer_id',
        'decision_note',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'encrypted_identity_snapshot' => 'encrypted:array',
            'reviewed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (PatientLinkRequest $request): void {
            if (blank($request->request_number)) {
                $year = now()->format('Y');
                $sequence = self::query()->whereYear('created_at', $year)->count() + 1;
                $request->request_number = sprintf('PLR-%s-%06d', $year, $sequence);
            }
        });
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Patient, $this>
     */
    public function reviewedPatient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'reviewed_patient_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    /**
     * @return HasMany<PatientLinkCandidate, $this>
     */
    public function candidates(): HasMany
    {
        return $this->hasMany(PatientLinkCandidate::class, 'link_request_id');
    }

    /**
     * @return MorphMany<AuditLog, $this>
     */
    public function auditLogs(): MorphMany
    {
        return $this->morphMany(AuditLog::class, 'subject');
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function isRejected(): bool
    {
        return $this->status === 'rejected';
    }

    public function isExpired(): bool
    {
        return $this->status === 'expired';
    }

    public function expiryReason(): ?string
    {
        $audit = $this->auditLogs()
            ->where('action', AuditEvent::PatientLinkRequestExpired->value)
            ->latest('id')
            ->first();
        $metadata = $audit?->metadata;

        if (! is_array($metadata) || ! is_string($metadata['reason'] ?? null)) {
            return null;
        }

        return $metadata['reason'];
    }

    public function expiryReasonLabel(): string
    {
        return match ($this->expiryReason()) {
            'account_identity_changed' => 'Account identity changed after submission.',
            'verified_contact_changed' => 'Verified contact data changed after submission.',
            'stale_identity_snapshot' => 'Account identity no longer matches the submitted snapshot.',
            default => 'Request expired; submit a new request for review.',
        };
    }
}
