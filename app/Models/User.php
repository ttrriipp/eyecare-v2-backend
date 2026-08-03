<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['first_name', 'middle_name', 'last_name', 'email', 'phone', 'address', 'date_of_birth', 'password', 'role_id', 'is_optometrist', 'privacy_notice_version', 'privacy_acknowledged_at'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser, HasAppAuthentication
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, InteractsWithAppAuthentication, Notifiable;

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
     * Keep the framework's conventional user-name attribute available as a
     * derived compatibility accessor without storing a duplicate database
     * column.
     */
    public function getNameAttribute(): string
    {
        return $this->full_name;
    }

    /**
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * @return HasOne<Patient, $this>
     */
    public function patient(): HasOne
    {
        return $this->hasOne(Patient::class);
    }

    /**
     * @return HasMany<ProviderHour, $this>
     */
    public function providerHours(): HasMany
    {
        return $this->hasMany(ProviderHour::class);
    }

    /**
     * @return HasMany<PatientAccountContact, $this>
     */
    public function contacts(): HasMany
    {
        return $this->hasMany(PatientAccountContact::class);
    }

    /**
     * @return HasMany<PatientLinkRequest, $this>
     */
    public function linkRequests(): HasMany
    {
        return $this->hasMany(PatientLinkRequest::class);
    }

    /**
     * @return HasMany<AppointmentRequest, $this>
     */
    public function appointmentRequests(): HasMany
    {
        return $this->hasMany(AppointmentRequest::class);
    }

    /**
     * @return HasManyThrough<PatientInvitation, Patient, $this>
     */
    public function patientInvitations(): HasManyThrough
    {
        return $this->hasManyThrough(
            PatientInvitation::class,
            Patient::class,
            'user_id',
            'patient_id',
            'id',
            'id',
        );
    }

    public function isAdmin(): bool
    {
        return $this->role->name === 'admin';
    }

    public function hasOptometristCapability(): bool
    {
        return $this->is_optometrist
            && in_array($this->role->name, ['admin', 'staff'], true);
    }

    public function canAccessPanel(Panel $panel): bool
    {
        if ($panel->getId() !== 'admin') {
            return false;
        }

        return in_array($this->role->name, ['admin', 'staff'], true);
    }

    public function scopeOptometrists(Builder $query): Builder
    {
        return $query
            ->where('is_optometrist', true)
            ->whereHas('role', fn (Builder $roleQuery): Builder => $roleQuery->whereIn('name', ['admin', 'staff']));
    }

    public function scopePatients(Builder $query): Builder
    {
        return $query->whereHas('role', fn (Builder $roleQuery): Builder => $roleQuery->where('name', 'patient'));
    }

    /**
     * @return HasManyThrough<Prescription, Patient, $this>
     */
    public function prescriptions(): HasManyThrough
    {
        return $this->hasManyThrough(
            Prescription::class,
            Patient::class,
            'id',
            'patient_id',
            'id',
            'user_id',
        );
    }

    /**
     * @return HasManyThrough<Appointment, Patient, $this>
     */
    public function appointments(): HasManyThrough
    {
        return $this->hasManyThrough(
            Appointment::class,
            Patient::class,
            'id',
            'patient_id',
            'id',
            'user_id',
        );
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'email_verified_at' => 'datetime',
            'is_optometrist' => 'boolean',
            'password' => 'hashed',
            'privacy_acknowledged_at' => 'datetime',
        ];
    }
}
