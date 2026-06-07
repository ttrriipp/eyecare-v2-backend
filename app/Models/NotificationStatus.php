<?php

namespace App\Models;

use Database\Factories\NotificationStatusFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name'])]
class NotificationStatus extends Model
{
    /** @use HasFactory<NotificationStatusFactory> */
    use HasFactory;
}
