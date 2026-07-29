<?php

namespace App\Services\Eyewear;

use App\Enums\EyewearPaymentStatus;
use App\Enums\EyewearProgress;

class EyewearAggregate
{
    public function __construct(
        public readonly string $key,
        public readonly string $description,
        public readonly ?string $consultationAt,
        public readonly string $createdAt,
        public readonly EyewearProgress $progress,
        public readonly ?EyewearPaymentStatus $paymentStatus,
        public readonly string $totalAmount,
        public readonly ?string $balanceDue,
        public readonly string $activityAt,
        public readonly ?array $estimate,
        public readonly ?array $preparation,
        public readonly ?array $dispensing,
        public readonly ?array $paymentSummary,
    ) {}
}
