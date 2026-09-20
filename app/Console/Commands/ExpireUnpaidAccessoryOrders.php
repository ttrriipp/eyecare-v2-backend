<?php

namespace App\Console\Commands;

use App\Actions\OpticalOrders\CancelOpticalOrder;
use App\Enums\JobOrderStatus;
use App\Models\JobOrder;
use Illuminate\Console\Command;

class ExpireUnpaidAccessoryOrders extends Command
{
    protected $signature = 'accessory-orders:expire-unpaid';

    protected $description = 'Cancel pending_payment orders whose payment window has expired';

    public function handle(CancelOpticalOrder $cancelOrder): int
    {
        $expired = JobOrder::query()
            ->where('status', JobOrderStatus::PendingPayment)
            ->whereNotNull('payment_expires_at')
            ->where('payment_expires_at', '<=', now())
            ->get();

        $count = 0;

        foreach ($expired as $order) {
            try {
                $cancelOrder->handle(
                    jobOrder: $order,
                    reason: 'Payment window expired',
                    actor: null,
                );
                $count++;
            } catch (\Throwable $e) {
                $this->error("Failed to expire order {$order->job_order_number}: {$e->getMessage()}");
            }
        }

        $this->info("Expired {$count} unpaid accessory orders.");

        return self::SUCCESS;
    }
}
