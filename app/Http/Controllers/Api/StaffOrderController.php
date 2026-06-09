<?php

namespace App\Http\Controllers\Api;

use App\Actions\Orders\UpdateOrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\UpdateOrderStatusRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use Illuminate\Http\JsonResponse;

class StaffOrderController extends Controller
{
    public function updateStatus(
        UpdateOrderStatusRequest $request,
        Order $order,
        UpdateOrderStatus $updateOrderStatus,
    ): JsonResponse {
        $order = $updateOrderStatus->handle(
            order: $order,
            statusName: $request->validated('status'),
        );

        return response()->json([
            'data' => OrderResource::make($order),
        ]);
    }
}
