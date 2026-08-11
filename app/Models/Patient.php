<?php

namespace App\Models;

use App\Actions\PatientAccounts\CreateContactLookupHash;
use App\Actions\PatientAccounts\NormalizeContact;
use Database\Factories\PatientFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Validation\ValidationException;

#[Fillable([
    'user_id',
    'first_name',
    'middle_name',
    'last_name',
    'date_of_birth',
    'occupation',
    'address',
    'gender',
    'contact_email',
    'phone',
])]
class Patient extends Model
{
    /** @use HasFactory<PatientFactory> */
    use HasFactory, SoftDeletes;

    protected static function booted(): void
    {
        static::creating(function (Patient $patient): void {
            if (blank($patient->patient_number)) {
                $year = now()->format('Y');
                $sequence = self::query()
                    ->whereYear('created_at', $year)
                    ->withTrashed()
                    ->count() + 1;
                $patient->patient_number = sprintf('PAT-%s-%06d', $year, $sequence);
            }
        });

        static::saving(function (Patient $patient): void {
            if ($patient->isDirty('phone') && filled($patient->phone)) {
                try {
                    $patient->phone_lookup_hash = app(CreateContactLookupHash::class)->forPhone($patient->phone);
                } catch (\InvalidArgumentException) {
                    throw ValidationException::withMessages([
                        'phone' => ['Please enter a valid Philippine phone number (e.g., 9171234567).'],
                    ]);
                }
            } elseif ($patient->isDirty('phone')) {
                $patient->phone_lookup_hash = null;
            }

            if ($patient->isDirty('contact_email')) {
                $patient->contact_email_lookup_hash = filled($patient->contact_email)
                    ? app(CreateContactLookupHash::class)->forEmail($patient->contact_email)
                    : null;
            }
        });
    }

    /**
     * Normalize phone number to +63XXXXXXXXXX format.
     */
    protected function setPhoneAttribute(?string $value): void
    {
        if ($value === null) {
            $this->attributes['phone'] = null;

            return;
        }

        try {
            $this->attributes['phone'] = app(NormalizeContact::class)->phone($value);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages([
                'phone' => ['Please enter a valid Philippine phone number (e.g., 9171234567).'],
            ]);
        }
    }

    /**
     * Derived full name from structured names.
     */
    public function getFullNameAttribute(): string
    {
        $parts = array_filter([
            $this->first_name,
            $this->middle_name,
            $this->last_name,
        ]);

        return implode(' ', $parts);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return HasMany<Prescription, $this>
     */
    public function prescriptions(): HasMany
    {
        return $this->hasMany(Prescription::class);
    }

    /**
     * @return HasMany<Appointment, $this>
     */
    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    /**
     * @return HasMany<PatientIntake, $this>
     */
    public function intakes(): HasMany
    {
        return $this->hasMany(PatientIntake::class);
    }

    /**
     * @return HasMany<JobOrder, $this>
     */
    public function jobOrders(): HasMany
    {
        return $this->hasMany(JobOrder::class);
    }

    /**
     * @return HasMany<Encounter, $this>
     */
    public function encounters(): HasMany
    {
        return $this->hasMany(Encounter::class);
    }

    /**
     * @return HasMany<PatientInvitation, $this>
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(PatientInvitation::class);
    }

    /**
     * @return HasMany<BillingRecord, $this>
     */
    public function billingRecords(): HasMany
    {
        return $this->hasMany(BillingRecord::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
        ];
    }
}
