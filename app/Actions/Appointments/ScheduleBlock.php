<?php

namespace App\Actions\Appointments;

use Carbon\CarbonInterface;

readonly class ScheduleBlock
{
    public function __construct(
        public CarbonInterface $startsAt,
        public CarbonInterface $endsAt,
        public string $source, // 'appointment' or 'request'
        public ?int $sourceId = null,
        public ?int $optometristId = null,
    ) {}

    public function overlaps(CarbonInterface $start, CarbonInterface $end): bool
    {
        return $this->startsAt->lt($end) && $this->endsAt->gt($start);
    }

    public function excludes(?int $excludeSourceId, string $excludeSource): bool
    {
        return $excludeSourceId !== null
            && $this->source === $excludeSource
            && $this->sourceId === $excludeSourceId;
    }
}
