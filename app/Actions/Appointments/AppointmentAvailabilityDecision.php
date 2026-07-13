<?php

namespace App\Actions\Appointments;

use Carbon\CarbonInterface;

class AppointmentAvailabilityDecision
{
    public function __construct(
        public readonly CarbonInterface $startsAt,
        public readonly CarbonInterface $endsAt,
        public readonly bool $available,
        public readonly ?string $reason = null,
    ) {}

    public static function available(CarbonInterface $startsAt, CarbonInterface $endsAt): self
    {
        return new self(
            startsAt: $startsAt->copy(),
            endsAt: $endsAt->copy(),
            available: true,
        );
    }

    public static function unavailable(CarbonInterface $startsAt, CarbonInterface $endsAt, string $reason): self
    {
        return new self(
            startsAt: $startsAt->copy(),
            endsAt: $endsAt->copy(),
            available: false,
            reason: $reason,
        );
    }
}
