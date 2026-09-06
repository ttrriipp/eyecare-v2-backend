<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\PilotParticipantAccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'participant_code', 'expires_at', 'revoked_at'])]
class PilotParticipantAccount extends Model
{
    /** @use HasFactory<PilotParticipantAccountFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope accounts that can authenticate at a given instant.
     */
    public function scopeEligibleAt(Builder $query, ?CarbonInterface $at = null): Builder
    {
        $at ??= now();

        return $query
            ->whereNull('revoked_at')
            ->where('expires_at', '>', $at)
            ->whereHas('user', function (Builder $userQuery): void {
                $userQuery
                    ->where('is_active', true)
                    ->whereHas(
                        'roles',
                        fn (Builder $roleQuery): Builder => $roleQuery->where('name', Role::Patient),
                    );
            });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }
}
