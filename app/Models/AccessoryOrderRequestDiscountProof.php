<?php

namespace App\Models;

use App\Enums\DiscountProofStatus;
use Database\Factories\AccessoryOrderRequestDiscountProofFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'accessory_order_request_id',
    'user_id',
    'status',
    'file_path',
    'original_name',
    'mime_type',
    'file_size',
    'reviewed_by',
    'reviewed_at',
    'rejection_reason',
])]
class AccessoryOrderRequestDiscountProof extends Model
{
    /** @use HasFactory<AccessoryOrderRequestDiscountProofFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => DiscountProofStatus::class,
            'file_size' => 'integer',
            'reviewed_at' => 'datetime',
        ];
    }

    public function accessoryOrderRequest(): BelongsTo
    {
        return $this->belongsTo(AccessoryOrderRequest::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function isPending(): bool
    {
        return $this->status === DiscountProofStatus::Pending;
    }

    public function isTerminal(): bool
    {
        return $this->status->isTerminal();
    }
}
