<?php

namespace App\Models;

use Database\Factories\AppointmentStatusFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name'])]
class AppointmentStatus extends Model
{
    /** @use HasFactory<AppointmentStatusFactory> */
    use HasFactory;
}
