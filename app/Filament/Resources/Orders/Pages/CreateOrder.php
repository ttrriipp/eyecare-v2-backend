<?php

namespace App\Filament\Resources\Orders\Pages;

use App\Filament\Resources\Orders\OrderResource;
use App\Models\LensType;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\Prescription;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\User;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Wizard\Step;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class CreateOrder extends CreateRecord
{
    use CreateRecord\Concerns\HasWizard;

    protected static string $resource = OrderResource::class;

    protected function getSteps(): array
    {
        return [
            Step::make('Order Details')
                ->schema([
                    TextInput::make('order_number')
                        ->label('Number')
                        ->disabled()
                        ->dehydrated()
                        ->default(fn (): string => 'ORD-'.now()->format('Y').'-'.str_pad(
                            (Order::query()->withTrashed()->count() + 1),
                            6,
                            '0',
                            STR_PAD_LEFT
                        )),
                    Select::make('customer_id')
                        ->label('Customer')
                        ->relationship('customer', 'name')
                        ->required()
                        ->searchable()
                        ->preload()
                        ->live()
                        ->createOptionForm([
                            TextInput::make('name')->required(),
                            TextInput::make('phone')->required()->tel(),
                            TextInput::make('email')->email()->nullable(),
                        ])
                        ->createOptionUsing(function (array $data): int {
                            return User::create([
                                'name' => $data['name'],
                                'phone' => $data['phone'],
                                'email' => $data['email'] ?? null,
                                'password' => null,
                                'role_id' => Role::query()->where('name', 'customer')->value('id'),
                            ])->getKey();
                        }),
                    ToggleButtons::make('order_status_id')
                        ->label('Status')
                        ->options(fn () => [
                            OrderStatus::query()->where('name', 'requested')->value('id') => 'Requested',
                        ])
                        ->colors(fn () => [
                            OrderStatus::query()->where('name', 'requested')->value('id') => 'gray',
                        ])
                        ->default(fn () => OrderStatus::query()->where('name', 'requested')->value('id'))
                        ->required()
                        ->disabled()
                        ->dehydrated()
                        ->columnSpanFull(),
                    Toggle::make('is_non_prescription')
                        ->label('Non-Prescription Order')
                        ->default(true)
                        ->live(),
                    Select::make('prescription_id')
                        ->label('Prescription')
                        ->options(function (Get $get): array {
                            $customerId = $get('customer_id');
                            if (! $customerId) {
                                return [];
                            }

                            return Prescription::query()
                                ->where('customer_id', $customerId)
                                ->get()
                                ->mapWithKeys(fn ($p) => [
                                    $p->id => "#{$p->id} — {$p->prescribed_at->format('M j, Y')} (expires {$p->expires_at->format('M j, Y')})",
                                ])
                                ->toArray();
                        })
                        ->visible(fn (Get $get): bool => ! $get('is_non_prescription'))
                        ->nullable(),
                    RichEditor::make('notes')
                        ->label('Staff Notes')
                        ->toolbarButtons(['bold', 'italic', 'bulletList', 'orderedList'])
                        ->columnSpanFull(),
                ])
                ->columns(2),

            Step::make('Order Items')
                ->schema([
                    Repeater::make('items')
                        ->hiddenLabel()
                        ->minItems(1)
                        ->schema([
                            Select::make('product_variant_id')
                                ->label('Product Variant')
                                ->options(fn () => ProductVariant::query()
                                    ->with('product')
                                    ->where('is_active', true)
                                    ->get()
                                    ->mapWithKeys(fn ($v) => [$v->id => "{$v->product->name} — {$v->name} (₱{$v->price})"]))
                                ->required()
                                ->searchable()
                                ->live()
                                ->afterStateUpdated(function (Set $set, ?int $state): void {
                                    if ($state) {
                                        $variant = ProductVariant::find($state);
                                        $set('unit_price', $variant?->price);
                                    }
                                }),
                            Select::make('lens_type_id')
                                ->label('Lens Type')
                                ->options(fn () => LensType::query()->pluck('name', 'id'))
                                ->nullable()
                                ->placeholder('No lens required')
                                ->live()
                                ->afterStateUpdated(function (Set $set, Get $get, ?int $state): void {
                                    $variantId = $get('product_variant_id');
                                    $variant = $variantId ? ProductVariant::find($variantId) : null;
                                    $lensType = $state ? LensType::find($state) : null;
                                    $unitPrice = (float) ($variant?->price ?? 0);
                                    $lensPrice = (float) ($lensType?->price ?? 0);
                                    $set('unit_price', number_format($unitPrice + $lensPrice, 2, '.', ''));
                                }),
                            TextInput::make('quantity')
                                ->required()
                                ->numeric()
                                ->minValue(1)
                                ->default(1)
                                ->live(),
                            TextInput::make('unit_price')
                                ->label('Unit Price')
                                ->prefix('₱')
                                ->disabled()
                                ->dehydrated(false),
                        ])
                        ->columns(4)
                        ->columnSpanFull()
                        ->defaultItems(1),
                ]),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $items = $data['items'] ?? [];
        unset($data['items']);

        return DB::transaction(function () use ($data, $items): Model {
            $subtotal = '0.00';
            $lineItems = [];

            foreach ($items as $item) {
                $variant = ProductVariant::query()->with('product')->findOrFail($item['product_variant_id']);
                $lensType = isset($item['lens_type_id']) && $item['lens_type_id']
                    ? LensType::query()->findOrFail($item['lens_type_id'])
                    : null;
                $quantity = (int) $item['quantity'];
                $unitPrice = (string) $variant->price;
                $lensTypePrice = $lensType?->price !== null ? (string) $lensType->price : '0.00';
                $lineSubtotal = bcmul(bcadd($unitPrice, $lensTypePrice, 2), (string) $quantity, 2);
                $subtotal = bcadd($subtotal, $lineSubtotal, 2);

                $lineItems[] = [
                    'product_variant_id' => $variant->id,
                    'lens_type_id' => $lensType?->id,
                    'product_id' => $variant->product_id,
                    'product_name' => $variant->product->name,
                    'variant_name' => $variant->name,
                    'variant_sku' => $variant->sku,
                    'lens_type_name' => $lensType?->name,
                    'lens_type_price' => $lensType?->price !== null ? (string) $lensType->price : null,
                    'unit_price' => $unitPrice,
                    'quantity' => $quantity,
                    'subtotal' => $lineSubtotal,
                ];
            }

            $data['subtotal'] = $subtotal;
            $data['total_amount'] = $subtotal;
            $data['discount_amount'] = '0.00';
            $data['order_status_id'] = OrderStatus::query()->where('name', 'requested')->value('id');

            /** @var Order $order */
            $order = static::getModel()::create($data);
            $order->items()->createMany($lineItems);

            return $order;
        });
    }
}
