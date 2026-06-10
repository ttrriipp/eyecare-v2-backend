<?php

namespace App\Models;

use Database\Factories\BillingStatusFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name'])]
class BillingStatus extends Model
{
    /** @use HasFactory<BillingStatusFactory> */
    use HasFactory;
}
