<?php

namespace App\Filament\Resources\Billings\Pages;

use App\Filament\Resources\Billings\BillingResource;
use App\Models\Appointment;
use App\Models\BillingStatus;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class CreateBilling extends CreateRecord
{
    protected static string $resource = BillingResource::class;

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('customer_id')
                ->label('Customer')
                ->relationship('customer', 'name')
                ->required()
                ->searchable()
                ->preload()
                ->live(),
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
                ->searchable(),
            Textarea::make('notes')
                ->label('Notes')
                ->columnSpanFull(),
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
}
