<?php

namespace App\Models;

use Database\Factories\InventoryMovementStatusFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name'])]
class InventoryMovementStatus extends Model
{
    /** @use HasFactory<InventoryMovementStatusFactory> */
    use HasFactory;
}
