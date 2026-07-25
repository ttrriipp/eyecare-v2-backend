<?php

namespace App\Models;

use Database\Factories\ClinicHourFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'weekday',
    'open_time',
    'close_time',
    'enabled',
])]
class ClinicHour extends Model
{
    /** @use HasFactory<ClinicHourFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'weekday' => 'integer',
            'open_time' => 'datetime:H:i',
            'close_time' => 'datetime:H:i',
            'enabled' => 'boolean',
        ];
    }
}
