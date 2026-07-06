<?php

namespace App\Filament\Resources\Orders\Pages;

use App\Filament\Resources\Orders\OrderResource;
use App\Models\Billing;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\Prescription;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\User;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Wizard\Step;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;

class CreateOrder extends CreateRecord
{
    use CreateRecord\Concerns\HasWizard;

    protected static string $resource = OrderResource::class;

    /**
     * When set (via the "Create Order" action on a billing), the new order is
     * pre-linked to this billing. Its items attach to that billing once the
     * order reaches `processing`, instead of generating a new billing.
     */
    #[Url(as: 'billing_id')]
    public ?int $preLinkedBillingId = null;

    public function getSubheading(): ?string
    {
        if (! $this->preLinkedBillingId) {
            return null;
        }

        $billing = Billing::find($this->preLinkedBillingId);

        return $billing
            ? "This order will be linked to billing #{$billing->billing_number}."
            : null;
    }

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
                        ->default(fn (): ?int => $this->preLinkedBillingId
                            ? Billing::find($this->preLinkedBillingId)?->customer_id
                            : null)
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
                    Toggle::make('is_non_prescription')
                        ->label('No lens cutting required')
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
                    Textarea::make('notes')
                        ->label('Staff Notes')
                        ->columnSpanFull(),
                ])
                ->columns(2),

            Step::make('Order Items')
                ->schema([
                    Section::make()->schema([
                        Repeater::make('items')
                            ->hiddenLabel()
                            ->minItems(1)
                            ->reorderable()
                            ->addActionLabel('Add to order items')
                            ->table([
                                TableColumn::make('Product')->width('70%'),
                                TableColumn::make('Qty')->width('15%'),
                                TableColumn::make('Unit Price')->width('15%'),
                            ])
                            ->schema([
                                Select::make('product_variant_id')
                                    ->label('Product')
                                    ->options(fn () => ProductVariant::query()
                                        ->with('product')
                                        ->where('is_active', true)
                                        ->whereHas('product', fn ($q) => $q->whereIn('product_type', ['frame', 'general']))
                                        ->get()
                                        ->mapWithKeys(fn ($v) => [
                                            $v->id => $v->stock_quantity > 0
                                                ? "{$v->product->name} — {$v->name} (stock: {$v->stock_quantity})"
                                                : "⚠ {$v->product->name} — {$v->name} [OUT OF STOCK]",
                                        ]))
                                    ->required()
                                    ->live()
                                    ->afterStateUpdated(function (Set $set, ?int $state): void {
                                        if ($state) {
                                            $variant = ProductVariant::find($state);
                                            $set('unit_price', number_format((float) ($variant?->price ?? 0), 2, '.', ''));
                                        }
                                    }),
                                TextInput::make('quantity')
                                    ->label('Qty')
                                    ->required()
                                    ->numeric()
                                    ->minValue(1)
                                    ->default(1),
                                TextInput::make('unit_price')
                                    ->label('Unit Price')
                                    ->prefix('₱')
                                    ->disabled()
                                    ->dehydrated(false),
                            ])
                            ->columnSpanFull()
                            ->defaultItems(1),
                    ]),
                ]),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        if ($this->preLinkedBillingId) {
            $billing = Billing::findOrFail($this->preLinkedBillingId);

            if ((int) $billing->customer_id !== (int) $data['customer_id']) {
                throw ValidationException::withMessages([
                    'customer_id' => ['The selected customer must match the billing\'s customer.'],
                ]);
            }
        }

        $items = $data['items'] ?? [];
        unset($data['items']);

        return DB::transaction(function () use ($data, $items): Model {
            $subtotal = '0.00';
            $lineItems = [];

            foreach ($items as $item) {
                $variant = ProductVariant::query()->with('product')->findOrFail($item['product_variant_id']);
                $quantity = (int) $item['quantity'];
                $unitPrice = (string) $variant->price;
                $lineSubtotal = bcmul($unitPrice, (string) $quantity, 2);
                $subtotal = bcadd($subtotal, $lineSubtotal, 2);

                $lineItems[] = [
                    'product_variant_id' => $variant->id,
                    'product_id' => $variant->product_id,
                    'product_name' => $variant->product->name,
                    'variant_name' => $variant->name,
                    'variant_sku' => $variant->sku,
                    'unit_price' => $unitPrice,
                    'quantity' => $quantity,
                    'subtotal' => $lineSubtotal,
                ];
            }

            $data['subtotal'] = $subtotal;
            $data['total_amount'] = $subtotal;
            $data['order_status_id'] = OrderStatus::query()->where('name', 'confirmed')->value('id');
            $data['billing_id'] = $this->preLinkedBillingId;

            /** @var Order $order */
            $order = static::getModel()::create($data);
            $order->items()->createMany($lineItems);

            return $order;
        });
    }
}
