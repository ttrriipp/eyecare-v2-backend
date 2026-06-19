<?php

namespace App\Filament\Resources\Orders\Schemas;

use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\Prescription;
use App\Models\Role;
use App\Models\User;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class OrderForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Grid::make(3)->schema([
                // ── Main (2/3) ──────────────────────────────────────
                Grid::make(1)->columnSpan(2)->schema([
                    Section::make()->schema([
                        TextInput::make('order_number')
                            ->label('Number')
                            ->disabled()
                            ->dehydrated(),
                        Select::make('customer_id')
                            ->label('Customer')
                            ->relationship('customer', 'name')
                            ->required()
                            ->searchable()
                            ->preload()
                            ->disabledOn('edit')
                            ->dehydrated()
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
                            ->options(function (?Order $record): array {
                                if (! $record) {
                                    return [];
                                }

                                $transitions = [
                                    'requested' => ['confirmed', 'cancelled'],
                                    'confirmed' => ['preparing', 'cancelled'],
                                    'preparing' => ['ready_for_pickup', 'cancelled'],
                                    'ready_for_pickup' => ['completed', 'cancelled'],
                                    'completed' => [],
                                    'cancelled' => [],
                                ];

                                $order = ['requested', 'confirmed', 'preparing', 'ready_for_pickup', 'completed', 'cancelled'];

                                $currentName = $record->status->name;
                                $allowed = $transitions[$currentName] ?? [];
                                $visible = [$currentName, ...$allowed];

                                return OrderStatus::query()
                                    ->whereIn('name', $visible)
                                    ->get()
                                    ->sortBy(fn ($s) => array_search($s->name, $order))
                                    ->mapWithKeys(fn ($s) => [$s->id => ucwords(str_replace('_', ' ', $s->name))])
                                    ->toArray();
                            })
                            ->colors(fn (?Order $record): array => [
                                OrderStatus::query()->where('name', 'requested')->value('id') => 'gray',
                                OrderStatus::query()->where('name', 'confirmed')->value('id') => 'info',
                                OrderStatus::query()->where('name', 'preparing')->value('id') => 'warning',
                                OrderStatus::query()->where('name', 'ready_for_pickup')->value('id') => 'success',
                                OrderStatus::query()->where('name', 'completed')->value('id') => 'success',
                                OrderStatus::query()->where('name', 'cancelled')->value('id') => 'danger',
                            ])
                            ->inline()
                            ->disabledOn('create')
                            ->dehydrated()
                            ->hiddenOn('create')
                            ->columnSpanFull(),
                        Toggle::make('is_non_prescription')
                            ->label('Non-Prescription Order')
                            ->default(true)
                            ->disabledOn('edit')
                            ->dehydrated()
                            ->live(),
                        Select::make('prescription_id')
                            ->label('Prescription')
                            ->options(function (Get $get, ?Order $record): array {
                                $customerId = $get('customer_id') ?? $record?->customer_id;
                                if (! $customerId) {
                                    return [];
                                }

                                return Prescription::query()
                                    ->where('customer_id', $customerId)
                                    ->get()
                                    ->mapWithKeys(fn ($p) => [
                                        $p->id => "#{$p->id} — {$p->prescribed_at->format('M j, Y')}",
                                    ])
                                    ->toArray();
                            })
                            ->visible(fn (Get $get): bool => ! $get('is_non_prescription'))
                            ->disabled()
                            ->dehydrated(),
                        TextInput::make('total_amount')
                            ->label('Total')
                            ->disabled()
                            ->dehydrated()
                            ->prefix('₱'),
                        RichEditor::make('notes')
                            ->label('Staff Notes')
                            ->toolbarButtons(['bold', 'italic', 'bulletList', 'orderedList'])
                            ->columnSpanFull(),
                    ])->columns(2),
                ]),

                // ── Sidebar (1/3) ────────────────────────────────────
                Grid::make(1)->columnSpan(1)->schema([
                    Section::make()->schema([
                        Placeholder::make('created_at')
                            ->label('Order date')
                            ->content(fn (?Order $record): string => $record?->created_at?->diffForHumans() ?? '—'),
                        Placeholder::make('updated_at')
                            ->label('Last modified at')
                            ->content(fn (?Order $record): string => $record?->updated_at?->diffForHumans() ?? '—'),
                    ])->hiddenOn('create'),
                ]),
            ]),
        ]);
    }
}
