<?php

namespace App\Actions\AccessoryOrderRequests;

use App\Actions\Notifications\NotifyAdminUsers;
use App\Enums\AccessoryOrderRequestStatus;
use App\Enums\CommercialItemKind;
use App\Exceptions\AccessoryNotOrderableException;
use App\Exceptions\ActiveOrderRequestExistsException;
use App\Models\AccessoryOrderRequest;
use App\Models\AccessoryOrderRequestItem;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SubmitAccessoryOrderRequest
{
    public function __construct(private readonly NotifyAdminUsers $notifyAdminUsers) {}

    /**
     * Submit a new accessory order request.
     *
     * @param  array<int, array{product_variant_id: int, quantity: int}>  $items
     */
    public function handle(
        User $account,
        array $items,
        string $requestedDiscountType = 'none',
    ): AccessoryOrderRequest {
        return DB::transaction(function () use ($account, $items, $requestedDiscountType): AccessoryOrderRequest {
            // Lock account and check one-pending-request limit
            $account = User::query()->lockForUpdate()->findOrFail($account->id);
            $account->load('patient');

            if ($account->patient === null) {
                throw ValidationException::withMessages([
                    'account' => ['An active patient link is required to submit an accessory order request.'],
                ]);
            }

            $existingPending = AccessoryOrderRequest::query()
                ->where('user_id', $account->id)
                ->where('status', AccessoryOrderRequestStatus::Pending)
                ->exists();

            if ($existingPending) {
                throw new ActiveOrderRequestExistsException;
            }

            // Validate items
            if (count($items) < 1 || count($items) > 20) {
                throw ValidationException::withMessages([
                    'items' => ['A request must contain between 1 and 20 items.'],
                ]);
            }

            $variantIds = collect($items)
                ->pluck('product_variant_id')
                ->map(fn (mixed $variantId): int => (int) $variantId);

            if ($variantIds->count() !== $variantIds->unique()->count()) {
                throw ValidationException::withMessages([
                    'items' => ['Each accessory variant may appear only once in a request.'],
                ]);
            }

            // Load and validate variants
            $variants = ProductVariant::query()
                ->whereIn('id', $variantIds->sort()->values())
                ->where('is_active', true)
                ->whereHas('product', fn ($q) => $q->active()->where('product_type', 'accessory'))
                ->with('product')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $subtotal = 0;
            $itemData = [];

            foreach ($items as $item) {
                $variantId = $item['product_variant_id'];
                $quantity = (int) $item['quantity'];

                if ($quantity < 1 || $quantity > 5) {
                    throw ValidationException::withMessages([
                        'items' => ['Each accessory quantity must be between 1 and 5.'],
                    ]);
                }

                $variant = $variants->get($variantId);

                if ($variant === null) {
                    throw new AccessoryNotOrderableException;
                }

                if (($variant->usableStockQuantity() ?? 0) < $quantity) {
                    throw new AccessoryNotOrderableException;
                }

                $amount = (int) round(((float) $variant->price) * 100) * $quantity;
                $subtotal += $amount;

                $itemData[] = [
                    'product_variant_id' => $variant->id,
                    'description' => $variant->product->name.' — '.$variant->name,
                    'quantity' => $quantity,
                    'unit_price' => $variant->price,
                    'amount' => $amount / 100,
                    'item_kind' => CommercialItemKind::Accessory,
                    'item_snapshot' => [
                        'product_variant_id' => $variant->id,
                        'sku' => $variant->sku,
                        'variant_name' => $variant->name,
                        'product_name' => $variant->product->name,
                        'price' => $variant->price,
                        'attributes' => $variant->attributes,
                        'images' => $this->publicImages($variant->images)
                            ?: $this->publicImages($variant->product->images),
                    ],
                ];
            }

            // Create request
            $request = AccessoryOrderRequest::create([
                'user_id' => $account->id,
                'patient_id' => $account->patient->id,
                'status' => AccessoryOrderRequestStatus::Pending,
                'subtotal_amount' => $subtotal / 100,
                'requested_discount_type' => $requestedDiscountType,
            ]);

            // Create items
            foreach ($itemData as $data) {
                $data['accessory_order_request_id'] = $request->id;
                AccessoryOrderRequestItem::create($data);
            }

            $request = $request->fresh(['items', 'patient']);
            $this->notifyAdminUsers->accessoryOrderRequestSubmitted($request);

            return $request;
        });
    }

    /**
     * @param  array<int, mixed>|null  $images
     * @return list<string>
     */
    private function publicImages(?array $images): array
    {
        return collect($images ?? [])
            ->filter(fn (mixed $image): bool => is_string($image))
            ->map(fn (string $image): string => trim($image))
            ->filter(fn (string $image): bool => $this->isPublicImageReference($image))
            ->values()
            ->all();
    }

    private function isPublicImageReference(string $image): bool
    {
        if (
            $image === ''
            || str_contains($image, '\\')
            || str_contains($image, '..')
            || str_starts_with($image, '/')
            || filter_var($image, FILTER_VALIDATE_URL) !== false
        ) {
            return false;
        }

        $path = parse_url($image, PHP_URL_PATH);

        if (! is_string($path)) {
            return false;
        }

        return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), [
            'avif',
            'bmp',
            'gif',
            'jpeg',
            'jpg',
            'png',
            'svg',
            'tif',
            'tiff',
            'webp',
        ], true);
    }
}
