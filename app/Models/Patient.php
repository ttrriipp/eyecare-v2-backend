<?php

namespace App\Models;

use Database\Factories\PatientFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

#[Fillable([
    'user_id',
    'full_name',
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
                $patient->patient_number = 'PAT-'.Str::ulid();
            }
        });
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return HasManyThrough<Prescription, User, $this>
     */
    public function prescriptions(): HasManyThrough
    {
        return $this->hasManyThrough(
            Prescription::class,
            User::class,
            'id',
            'customer_id',
            'user_id',
            'id',
        );
    }

    /**
     * @return HasManyThrough<Appointment, User, $this>
     */
    public function appointments(): HasManyThrough
    {
        return $this->hasManyThrough(
            Appointment::class,
            User::class,
            'id',
            'customer_id',
            'user_id',
            'id',
        );
    }

    /**
     * @return HasManyThrough<Order, User, $this>
     */
    public function orders(): HasManyThrough
    {
        return $this->hasManyThrough(
            Order::class,
            User::class,
            'id',
            'customer_id',
            'user_id',
            'id',
        );
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
