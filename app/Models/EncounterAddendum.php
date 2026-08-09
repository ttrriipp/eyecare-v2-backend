<?php

namespace App\Models;

use App\Enums\EncounterAddendumType;
use Database\Factories\EncounterAddendumFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'encounter_id',
    'sequence_number',
    'type',
    'reason',
    'content',
    'authored_by',
    'authored_at',
])]
class EncounterAddendum extends Model
{
    /** @use HasFactory<EncounterAddendumFactory> */
    use HasFactory;

    protected $table = 'encounter_addenda';

    /**
     * @return BelongsTo<Encounter, $this>
     */
    public function encounter(): BelongsTo
    {
        return $this->belongsTo(Encounter::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'authored_by');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => EncounterAddendumType::class,
            'reason' => 'encrypted',
            'content' => 'encrypted',
            'authored_at' => 'datetime',
            'sequence_number' => 'integer',
        ];
    }
}
