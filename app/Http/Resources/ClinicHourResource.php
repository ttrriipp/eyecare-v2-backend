<?php

namespace App\Http\Resources;

use App\Models\ClinicHour;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ClinicHour
 */
class ClinicHourResource extends JsonResource
{
    private const DAY_NAMES = [
        0 => 'Sunday',
        1 => 'Monday',
        2 => 'Tuesday',
        3 => 'Wednesday',
        4 => 'Thursday',
        5 => 'Friday',
        6 => 'Saturday',
    ];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $weekday = (int) $this->weekday;
        $enabled = (bool) $this->enabled;

        return [
            'weekday' => $weekday,
            'day_name' => self::DAY_NAMES[$weekday],
            'enabled' => $enabled,
            'open_time' => $enabled ? $this->open_time?->format('H:i') : null,
            'close_time' => $enabled ? $this->close_time?->format('H:i') : null,
        ];
    }
}
