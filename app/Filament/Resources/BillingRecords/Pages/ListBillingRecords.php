<?php

namespace App\Filament\Resources\BillingRecords\Pages;

use App\Actions\BillingRecords\AddChargesToBilling;
use App\Actions\BillingRecords\ResolveOpenCheckoutBillingRecord;
use App\Enums\BillingItemSourceKind;
use App\Enums\BillingRecordStatus;
use App\Filament\Resources\BillingRecords\BillingRecordResource;
use App\Models\Patient;
use App\Models\Service;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
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
                ->modalHeading('Add Service Charge')
                ->modalWidth('3xl')
                ->modalSubmitActionLabel('Add to Billing')
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
                        ->itemLabel(fn (array $state): string => filled($state['description'] ?? null)
                            ? $state['description']
                            : 'New service line')
                        ->schema([
                            Radio::make('service_source')
                                ->label('Service source')
                                ->options([
                                    'catalog' => 'Catalog service',
                                    'custom' => 'Custom service',
                                ])
                                ->default('catalog')
                                ->inline()
                                ->live()
                                ->afterStateUpdated(function (Set $set, ?string $state): void {
                                    $set('service_id', null);
                                    $set('description', null);
                                    $set('unit_price', null);
                                    $set('line_total', null);

                                    if ($state === 'custom') {
                                        $set('quantity', 1);
                                    }
                                })
                                ->columnSpanFull(),
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
                                ->required(fn (Get $get): bool => $get('service_source') === 'catalog'
                                    && (filled($get('service_id'))
                                        || filled($get('description'))
                                        || filled($get('unit_price'))))
                                ->visible(fn (Get $get): bool => $get('service_source') === 'catalog')
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
                                ->required(fn (Get $get): bool => $get('service_source') === 'custom')
                                ->maxLength(255)
                                ->visible(fn (Get $get): bool => $get('service_source') === 'custom')
                                ->dehydrated()
                                ->dehydratedWhenHidden()
                                ->columnSpanFull(),
                            Grid::make(3)
                                ->columnSpanFull()
                                ->schema([
                                    TextInput::make('quantity')
                                        ->numeric()
                                        ->integer()
                                        ->minValue(1)
                                        ->default(1)
                                        ->required(fn (Get $get): bool => $get('service_source') === 'custom'
                                            || filled($get('service_id')))
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
                                        ->required(fn (Get $get): bool => $get('service_source') === 'custom'
                                            || filled($get('service_id')))
                                        ->helperText(fn (Get $get): ?string => $get('service_source') === 'catalog'
                                            ? 'Catalog-controlled'
                                            : null)
                                        ->disabled(fn (Get $get): bool => $get('service_source') === 'catalog')
                                        ->dehydrated()
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
                        $items = collect($data['items'] ?? [])
                            ->filter(fn (array $item): bool => collect([
                                $item['service_id'] ?? null,
                                $item['description'] ?? null,
                                $item['unit_price'] ?? null,
                            ])->contains(fn (mixed $value): bool => filled($value)))
                            ->map(function (array $item): array {
                                $service = null;
                                $serviceSource = $item['service_source']
                                    ?? (filled($item['service_id'] ?? null) ? 'catalog' : 'custom');

                                if ($serviceSource === 'catalog') {
                                    $service = filled($item['service_id'] ?? null)
                                        ? Service::query()->active()->find((int) $item['service_id'])
                                        : null;

                                    if ($service === null) {
                                        throw ValidationException::withMessages([
                                            'items' => ['Select an active catalog service for each catalog line.'],
                                        ]);
                                    }
                                }

                                $unitPrice = $service?->price ?? $item['unit_price'];
                                $unitPriceInCents = (int) round(((float) $unitPrice) * 100);
                                $amountInCents = $unitPriceInCents * (int) $item['quantity'];

                                return [
                                    'description' => trim($service?->name ?? $item['description']),
                                    'quantity' => (int) $item['quantity'],
                                    'unit_price' => number_format($unitPriceInCents / 100, 2, '.', ''),
                                    'amount' => number_format($amountInCents / 100, 2, '.', ''),
                                    'service_id' => $service?->id,
                                ];
                            })
                            ->values();

                        if ($items->isEmpty()) {
                            throw ValidationException::withMessages([
                                'items' => ['At least one service line is required.'],
                            ]);
                        }

                        $billingRecord = app(ResolveOpenCheckoutBillingRecord::class)->handle(
                            patient: $patient,
                        );

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
