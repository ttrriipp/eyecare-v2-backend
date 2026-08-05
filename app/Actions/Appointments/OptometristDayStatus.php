<?php

namespace App\Actions\Appointments;

use App\Models\User;

readonly class OptometristDayStatus
{
    public function __construct(
        public User $optometrist,
        public string $status,
        public ?string $startTime = null,
        public ?string $endTime = null,
        public ?string $reason = null,
    ) {}

    public static function notScheduled(User $optometrist): self
    {
        return new self(optometrist: $optometrist, status: 'not_scheduled');
    }

    public static function in(User $optometrist, string $startTime, string $endTime): self
    {
        return new self(optometrist: $optometrist, status: 'in', startTime: $startTime, endTime: $endTime);
    }

    public static function awayFullDay(User $optometrist, ?string $reason): self
    {
        return new self(optometrist: $optometrist, status: 'away_full', reason: $reason);
    }

    public static function awayPartialDay(User $optometrist, string $startTime, string $endTime, ?string $reason): self
    {
        return new self(optometrist: $optometrist, status: 'away_partial', startTime: $startTime, endTime: $endTime, reason: $reason);
    }
}
