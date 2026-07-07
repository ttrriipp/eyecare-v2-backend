<?php

namespace App\Filament\Resources\Billings\Pages;

use App\Actions\Billing\AddServiceToBilling;
use App\Filament\Resources\Billings\BillingResource;
use App\Models\Appointment;
use App\Models\Billing;
use App\Models\BillingStatus;
use App\Models\Service;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

class CreateBilling extends CreateRecord
{
    protected static string $resource = BillingResource::class;

    public function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make()->schema([
                Select::make('customer_id')
                    ->label('Customer')
                    ->relationship('customer', 'name')
                    ->required()
                    ->searchable()
                    ->preload()
                    ->live()
                    ->columnSpan(1),
                Select::make('appointment_id')
                    ->label('Appointment')
                    ->options(function (Get $get): array {
                        $customerId = $get('customer_id');
                        if (! $customerId) {
                            return [];
                        }

                        return Appointment::query()
                            ->where('customer_id', $customerId)
                            ->get()
                            ->mapWithKeys(fn ($a) => [
                                $a->id => "#{$a->id} — {$a->scheduled_at->format('M j, Y')}",
                            ])
                            ->toArray();
                    })
                    ->nullable()
                    ->placeholder('No appointment')
                    ->searchable()
                    ->columnSpan(1),
                Textarea::make('notes')
                    ->label('Notes')
                    ->columnSpanFull(),
            ])->columns(2),

            Section::make('Services')
                ->schema([
                    Repeater::make('services')
                        ->hiddenLabel()
                        ->schema([
                            Select::make('service_id')
                                ->label('Service')
                                ->options(fn () => Service::query()->active()->pluck('name', 'id'))
                                ->required()
                                ->searchable()
                                ->live()
                                ->columnSpan(2)
                                ->afterStateUpdated(function (Get $get, Set $set, ?int $state): void {
                                    if ($state && empty($get('amount'))) {
                                        $price = Service::find($state)?->price;
                                        if ($price !== null) {
                                            $set('amount', number_format((float) $price, 2, '.', ''));
                                        }
                                    }
                                }),
                            TextInput::make('amount')
                                ->label('Amount')
                                ->numeric()
                                ->minValue(0)
                                ->prefix('₱')
                                ->columnSpan(1),
                            Select::make('staff_id')
                                ->label('Performed by')
                                ->options(fn () => User::query()
                                    ->whereHas('role', fn ($q) => $q->whereIn('name', ['staff', 'admin']))
                                    ->pluck('name', 'id')
                                )
                                ->required()
                                ->default(fn () => auth()->id())
                                ->columnSpan(2),
                            DateTimePicker::make('performed_at')
                                ->label('Performed at')
                                ->required()
                                ->default(now())
                                ->columnSpan(1),
                        ])
                        ->columns(3)
                        ->defaultItems(0)
                        ->addActionLabel('Add service')
                        ->deleteAction(fn (Action $action) => $action->iconButton())
                        ->dehydrated(false),
                ]),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $issuedStatus = BillingStatus::query()->where('name', 'issued')->firstOrFail();

        $data['billing_status_id'] = $issuedStatus->id;
        $data['subtotal'] = '0.00';
        $data['discount_amount'] = '0.00';
        $data['total_amount'] = '0.00';
        $data['amount_paid'] = '0.00';
        $data['balance_due'] = '0.00';
        $data['issued_at'] = now();

        return $data;
    }

    protected function handleRecordCreation(array $data): Model
    {
        $services = $data['services'] ?? [];
        unset($data['services']);

        /** @var Billing $billing */
        $billing = static::getModel()::create($data);

        foreach ($services as $row) {
            $payload = [
                'service_id' => $row['service_id'],
                'staff_id' => $row['staff_id'],
                'performed_at' => $row['performed_at'],
                'appointment_id' => $billing->appointment_id,
            ];

            if (filled($row['amount'] ?? null)) {
                $payload['amount'] = $row['amount'];
            }

            app(AddServiceToBilling::class)->handle($billing, $payload);
        }

        return $billing->fresh();
    }
}
