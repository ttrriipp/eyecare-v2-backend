<?php

namespace App\Models;

use App\Enums\IncidentStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable([
    'reference_number',
    'title',
    'description',
    'scope',
    'status',
    'reported_by',
    'assigned_to',
    'containment_actions',
    'decisions',
    'resolution_notes',
    'discovered_at',
    'contained_at',
    'resolved_at',
    'closed_at',
])]
class PrivacyIncident extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(function (PrivacyIncident $incident): void {
            if (blank($incident->reference_number)) {
                $incident->reference_number = 'INC-'.Str::ulid();
            }
        });
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reportedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => IncidentStatus::class,
            'discovered_at' => 'datetime',
            'contained_at' => 'datetime',
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }
}
