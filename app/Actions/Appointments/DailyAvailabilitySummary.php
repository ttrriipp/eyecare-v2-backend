<?php

namespace App\Actions\Appointments;

use Carbon\CarbonInterface;

readonly class DailyAvailabilitySummary
{
    /**
     * @param  array<int, OptometristDayStatus>  $optometristStatuses
     */
    public function __construct(
        public CarbonInterface $date,
        public string $status,
        public ?string $openTime = null,
        public ?string $closeTime = null,
        public ?string $earlyCloseTime = null,
        public array $optometristStatuses = [],
    ) {}

    public static function closed(CarbonInterface $date): self
    {
        return new self(date: $date->copy(), status: 'closed');
    }

    /**
     * @param  array<int, OptometristDayStatus>  $optometristStatuses
     */
    public static function noOptometristAvailable(
        CarbonInterface $date,
        string $openTime,
        string $closeTime,
        ?string $earlyCloseTime,
        array $optometristStatuses,
    ): self {
        return new self(
            date: $date->copy(),
            status: 'no_optometrist',
            openTime: $openTime,
            closeTime: $closeTime,
            earlyCloseTime: $earlyCloseTime,
            optometristStatuses: $optometristStatuses,
        );
    }

    /**
     * @param  array<int, OptometristDayStatus>  $optometristStatuses
     */
    public static function open(
        CarbonInterface $date,
        string $openTime,
        string $closeTime,
        ?string $earlyCloseTime,
        array $optometristStatuses,
    ): self {
        return new self(
            date: $date->copy(),
            status: 'open',
            openTime: $openTime,
            closeTime: $closeTime,
            earlyCloseTime: $earlyCloseTime,
            optometristStatuses: $optometristStatuses,
        );
    }
}
