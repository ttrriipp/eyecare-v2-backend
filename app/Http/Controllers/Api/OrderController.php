<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreOrderRequest;
use App\Http\Resources\OrderResource;
use App\Models\LensType;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\ProductVariant;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OrderController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $orders = Order::query()
            ->where('customer_id', $request->user()->id)
            ->with(['status', 'items'])
            ->latest()
            ->get();

        return OrderResource::collection($orders);
    }

    public function store(StoreOrderRequest $request): JsonResponse
    {
        $requestedStatus = OrderStatus::query()->where('name', 'requested')->firstOrFail();

        $order = DB::transaction(function () use ($request, $requestedStatus): Order {
            $lineItems = [];
            $subtotal = '0.00';

            foreach ($request->validated('items') as $item) {
                $variant = ProductVariant::query()
                    ->with('product')
                    ->findOrFail($item['product_variant_id']);
                $lensType = LensType::query()->findOrFail($item['lens_type_id']);
                $quantity = (int) $item['quantity'];
                $unitPrice = (string) $variant->price;
                $lineSubtotal = bcmul($unitPrice, (string) $quantity, 2);
                $subtotal = bcadd($subtotal, $lineSubtotal, 2);

                $lineItems[] = [
                    'product_variant_id' => $variant->id,
                    'lens_type_id' => $lensType->id,
                    'product_id' => $variant->product_id,
                    'product_name' => $variant->product->name,
                    'variant_name' => $variant->name,
                    'variant_sku' => $variant->sku,
                    'lens_type_name' => $lensType->name,
                    'unit_price' => $unitPrice,
                    'quantity' => $quantity,
                    'subtotal' => $lineSubtotal,
                ];
            }

            $order = Order::query()->create([
                'order_number' => 'ORD-'.now()->format('Ymd').'-'.strtoupper(Str::random(6)),
                'customer_id' => $request->user()->id,
                'appointment_id' => $request->validated('appointment_id'),
                'is_non_prescription' => $request->boolean('is_non_prescription'),
                'order_status_id' => $requestedStatus->id,
                'subtotal' => $subtotal,
                'total_amount' => $subtotal,
                'discount_amount' => 0,
            ]);

            $order->items()->createMany($lineItems);

            return $order;
        });

        $order->load(['status', 'items', 'customer']);

        $staff = User::query()
            ->whereHas('role', fn ($q) => $q->whereIn('name', ['staff', 'admin']))
            ->get();

        Notification::make()
            ->title('New Order Request')
            ->body("{$order->customer->name} submitted order {$order->order_number}.")
            ->actions([
                Action::make('view')
                    ->label('View')
                    ->url('/admin/orders/'.$order->id.'/edit')
                    ->markAsRead(),
            ])
            ->sendToDatabase($staff);

        return response()->json([
            'data' => OrderResource::make($order),
        ], 201);
    }

    public function show(Request $request, Order $order): JsonResponse
    {
        abort_unless($order->customer_id === $request->user()->id, 404);

        $order->load(['status', 'items']);

        return response()->json([
            'data' => OrderResource::make($order),
        ]);
    }
}
