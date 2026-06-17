<?php

namespace App\Filament\Resources\Orders\Schemas;

use App\Models\LensType;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\User;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class OrderForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('order_number')
                    ->label('Order #')
                    ->disabled()
                    ->dehydrated(),
                Select::make('customer_id')
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
                Select::make('order_status_id')
                    ->relationship('status', 'name')
                    ->required()
                    ->live()
                    ->hiddenOn('create'),
                Toggle::make('is_non_prescription')
                    ->default(true)
                    ->disabledOn('edit')
                    ->dehydrated(),
                Select::make('prescription_id')
                    ->relationship('prescription', 'id')
                    ->label('Prescription')
                    ->disabled()
                    ->dehydrated(),
                TextInput::make('total_amount')
                    ->label('Total')
                    ->disabled()
                    ->dehydrated()
                    ->prefix('₱'),
                Textarea::make('notes')
                    ->label('Staff notes')
                    ->columnSpanFull(),
                Repeater::make('items')
                    ->hiddenOn('edit')
                    ->minItems(1)
                    ->schema([
                        Select::make('product_variant_id')
                            ->label('Variant')
                            ->options(fn () => ProductVariant::query()
                                ->with('product')
                                ->where('is_active', true)
                                ->get()
                                ->mapWithKeys(fn ($v) => [$v->id => "{$v->product->name} — {$v->name}"]))
                            ->required()
                            ->searchable(),
                        Select::make('lens_type_id')
                            ->label('Lens Type')
                            ->options(fn () => LensType::query()->pluck('name', 'id'))
                            ->required(),
                        TextInput::make('quantity')
                            ->required()
                            ->numeric()
                            ->minValue(1)
                            ->default(1),
                    ])
                    ->columns(3)
                    ->columnSpanFull()
                    ->defaultItems(1),
            ]);
    }
}
