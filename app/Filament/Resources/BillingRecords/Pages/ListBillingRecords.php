<?php

namespace App\Filament\Resources\BillingRecords\Pages;

use App\Actions\BillingRecords\AddChargesToBilling;
use App\Actions\BillingRecords\ResolveOpenCheckoutBillingRecord;
use App\Enums\BillingItemSourceKind;
use App\Enums\BillingRecordStatus;
use App\Enums\CommercialItemKind;
use App\Filament\Resources\BillingRecords\BillingRecordResource;
use App\Models\Patient;
use App\Models\Service;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class ListBillingRecords extends ListRecords
{
    protected static string $resource = BillingRecordResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('newServiceCharge')
                ->label('New Service Charge')
                ->icon('heroicon-o-plus-circle')
                ->color('primary')
                ->modalHeading('New Service Charge')
                ->modalDescription('Bill a performed service with no Quotation, Encounter, or Optical Order.')
                ->schema([
                    Select::make('patient_id')
                        ->label('Patient')
                        ->options(fn (): array => Patient::query()
                            ->get()
                            ->mapWithKeys(fn (Patient $patient): array => [$patient->id => $patient->full_name])
                            ->all())
                        ->required()
                        ->searchable()
                        ->preload(),

                    Repeater::make('items')
                        ->hiddenLabel()
                        ->schema([
                            Select::make('service_id')
                                ->label('Service')
                                ->options(fn (): array => Service::query()
                                    ->active()
                                    ->orderBy('name')
                                    ->get()
                                    ->mapWithKeys(fn (Service $service): array => [
                                        $service->id => "{$service->name} (₱".number_format((float) $service->price, 2).')',
                                    ])
                                    ->all())
                                ->nullable()
                                ->helperText('Optional. Leave blank for a custom service charge.')
                                ->searchable()
                                ->preload()
                                ->live()
                                ->columnSpanFull()
                                ->afterStateUpdated(function (Set $set, Get $get, mixed $state): void {
                                    $service = Service::query()->find($state);

                                    if ($service === null) {
                                        return;
                                    }

                                    $set('description', $service->name);
                                    $set('unit_price', $service->price);
                                    $set('line_total', number_format(
                                        ((float) ($get('quantity') ?? 1)) * ((float) $service->price),
                                        2,
                                    ));
                                }),
                            TextInput::make('description')
                                ->required()
                                ->maxLength(255)
                                ->columnSpanFull(),
                            Grid::make(3)
                                ->columnSpanFull()
                                ->schema([
                                    TextInput::make('quantity')
                                        ->numeric()
                                        ->integer()
                                        ->minValue(1)
                                        ->default(1)
                                        ->required()
                                        ->live(onBlur: true)
                                        ->afterStateUpdated(function (Set $set, Get $get): void {
                                            $set('line_total', number_format(
                                                ((float) ($get('quantity') ?? 0)) * ((float) ($get('unit_price') ?? 0)),
                                                2,
                                            ));
                                        }),
                                    TextInput::make('unit_price')
                                        ->label('Unit Price')
                                        ->numeric()
                                        ->prefix('₱')
                                        ->minValue(0)
                                        ->required()
                                        ->live(onBlur: true)
                                        ->afterStateUpdated(function (Set $set, Get $get): void {
                                            $set('line_total', number_format(
                                                ((float) ($get('quantity') ?? 0)) * ((float) ($get('unit_price') ?? 0)),
                                                2,
                                            ));
                                        }),
                                    TextInput::make('line_total')
                                        ->label('Line Total')
                                        ->prefix('₱')
                                        ->disabled()
                                        ->dehydrated(false),
                                ]),
                        ])
                        ->columns(2)
                        ->defaultItems(1)
                        ->minItems(1)
                        ->addActionLabel('Add Service Line'),

                    Placeholder::make('total')
                        ->label('Total')
                        ->content(function (Get $get): string {
                            $total = collect($get('items') ?? [])->sum(
                                fn (array $item): float => ((float) ($item['quantity'] ?? 0))
                                    * ((float) ($item['unit_price'] ?? 0)),
                            );

                            return '₱'.number_format($total, 2);
                        }),
                ])
                ->action(function (array $data): void {
                    $patient = Patient::query()->findOrFail($data['patient_id']);

                    try {
                        $billingRecord = app(ResolveOpenCheckoutBillingRecord::class)->handle(
                            patient: $patient,
                        );

                        $items = collect($data['items'])->map(function (array $item): array {
                            $unitPriceInCents = (int) round(((float) $item['unit_price']) * 100);
                            $amountInCents = $unitPriceInCents * (int) $item['quantity'];

                            return [
                                'description' => trim($item['description']),
                                'quantity' => (int) $item['quantity'],
                                'unit_price' => number_format($unitPriceInCents / 100, 2, '.', ''),
                                'amount' => number_format($amountInCents / 100, 2, '.', ''),
                                'item_type' => \App\Enums\TransactionItemType::Service,
                                'service_id' => $item['service_id'] ?? null,
                            ];
                        });

                        $billingRecord = app(AddChargesToBilling::class)->handle(
                            billingRecord: $billingRecord,
                            sourceKind: BillingItemSourceKind::DirectService,
                            items: $items,
                        );
                    } catch (ValidationException $e) {
                        Notification::make()->title('Cannot add charge')->body($e->getMessage())->danger()->send();

                        return;
                    }

                    Notification::make()->title('Service charge billed')->success()->send();

                    $this->redirect(BillingRecordResource::getUrl('edit', ['record' => $billingRecord]));
                }),
        ];
    }

    /**
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        return [
            'all' => Tab::make('All'),

            'outstanding' => Tab::make('Balances Due')
                ->modifyQueryUsing(fn (Builder $query) => $query
                    ->whereIn('status', [BillingRecordStatus::Unpaid, BillingRecordStatus::PartiallyPaid])),

            'overdue' => Tab::make('Overdue')
                ->modifyQueryUsing(fn (Builder $query) => $query
                    ->whereIn('status', [BillingRecordStatus::Unpaid, BillingRecordStatus::PartiallyPaid])
                    ->whereNotNull('payment_due_date')
                    ->where('payment_due_date', '<', today())),

            'paid' => Tab::make('Paid')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', BillingRecordStatus::Paid)),

            'voided' => Tab::make('Voided')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', BillingRecordStatus::Voided)),
        ];
    }
}
