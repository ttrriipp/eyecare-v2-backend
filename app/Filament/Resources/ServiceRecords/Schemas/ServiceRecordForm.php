<?php

namespace App\Filament\Resources\ServiceRecords\Schemas;

use App\Models\Appointment;
use App\Models\DiscountType;
use App\Models\Role;
use App\Models\Service;
use App\Models\User;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class ServiceRecordForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('customer_id')
                ->label('Patient')
                ->relationship('customer', 'name')
                ->required()
                ->searchable()
                ->preload()
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
                })
                ->live(),

            Select::make('appointment_id')
                ->label('Appointment (optional)')
                ->options(function (Get $get): array {
                    $customerId = $get('customer_id');
                    if (! $customerId) {
                        return [];
                    }

                    return Appointment::query()
                        ->where('customer_id', $customerId)
                        ->with(['visitReason', 'status'])
                        ->get()
                        ->mapWithKeys(fn (Appointment $a) => [
                            $a->id => $a->visitReason->name.' — '.$a->scheduled_at->format('M j, Y').' ('.$a->status->name.')',
                        ])
                        ->toArray();
                })
                ->searchable()
                ->nullable(),

            Select::make('service_id')
                ->label('Service')
                ->options(fn () => Service::query()->active()->pluck('name', 'id'))
                ->required()
                ->searchable()
                ->live()
                ->afterStateUpdated(function (Set $set, ?int $state): void {
                    if ($state) {
                        $price = Service::query()->find($state)?->price;
                        $set('amount', $price);
                        $set('total_amount', $price);
                    }
                }),

            TextInput::make('amount')
                ->label('Charge Amount')
                ->required()
                ->numeric()
                ->minValue(0)
                ->prefix('₱')
                ->helperText('Defaults from service price. Override if needed.')
                ->live(onBlur: true)
                ->afterStateUpdated(function (Set $set, Get $get, ?string $state): void {
                    self::recalculateTotal($set, $get);
                }),

            Select::make('discount_type_id')
                ->label('Discount')
                ->options(fn () => DiscountType::query()->where('is_active', true)->pluck('name', 'id'))
                ->nullable()
                ->live()
                ->afterStateUpdated(function (Set $set, Get $get): void {
                    self::recalculateTotal($set, $get);
                }),

            TextInput::make('discount_amount')
                ->label('Discount Amount')
                ->numeric()
                ->prefix('₱')
                ->default(0)
                ->readOnly()
                ->dehydrated(),

            TextInput::make('total_amount')
                ->label('Total')
                ->numeric()
                ->prefix('₱')
                ->default(0)
                ->readOnly()
                ->dehydrated(),

            Select::make('staff_id')
                ->label('Performed by')
                ->options(fn () => User::query()
                    ->whereHas('role', fn ($q) => $q->whereIn('name', ['staff', 'admin']))
                    ->pluck('name', 'id')
                )
                ->required()
                ->searchable()
                ->default(fn () => auth()->id()),

            DateTimePicker::make('performed_at')
                ->label('Performed at')
                ->required()
                ->default(now()),

            Textarea::make('notes')
                ->columnSpanFull(),
        ]);
    }

    private static function recalculateTotal(Set $set, Get $get): void
    {
        $amount = (float) ($get('amount') ?? 0);
        $discountTypeId = $get('discount_type_id');

        if (! $discountTypeId) {
            $set('discount_amount', '0.00');
            $set('total_amount', number_format($amount, 2, '.', ''));

            return;
        }

        $discountType = DiscountType::query()->find($discountTypeId);
        if (! $discountType) {
            return;
        }

        $discountAmount = $discountType->type === 'percentage'
            ? $amount * ((float) $discountType->value / 100)
            : (float) $discountType->value;

        $discountAmount = min($discountAmount, $amount);
        $total = $amount - $discountAmount;

        $set('discount_amount', number_format($discountAmount, 2, '.', ''));
        $set('total_amount', number_format($total, 2, '.', ''));
    }
}
