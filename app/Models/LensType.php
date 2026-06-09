<?php

namespace App\Models;

use Database\Factories\LensTypeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'description'])]
class LensType extends Model
{
    /** @use HasFactory<LensTypeFactory> */
    use HasFactory;
}
