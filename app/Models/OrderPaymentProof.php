<?php

namespace App\Models;

use App\Enums\OrderPaymentProofStatus;
use Database\Factories\OrderPaymentProofFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'job_order_id',
    'user_id',
    'status',
    'file_path',
    'original_name',
    'mime_type',
    'file_size',
    'sender_name',
    'reference_number',
    'reviewed_by',
    'reviewed_at',
    'rejection_reason',
])]
class OrderPaymentProof extends Model
{
    /** @use HasFactory<OrderPaymentProofFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => OrderPaymentProofStatus::class,
            'file_size' => 'integer',
            'reviewed_at' => 'datetime',
        ];
    }

    public function jobOrder(): BelongsTo
    {
        return $this->belongsTo(JobOrder::class);
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
        return $this->status === OrderPaymentProofStatus::Pending;
    }

    public function isTerminal(): bool
    {
        return $this->status->isTerminal();
    }
}
