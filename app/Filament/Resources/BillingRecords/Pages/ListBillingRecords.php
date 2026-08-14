<?php

namespace App\Filament\Resources\BillingRecords\Pages;

use App\Actions\BillingRecords\AddChargesToBilling;
use App\Actions\BillingRecords\ResolveOpenCheckoutBillingRecord;
use App\Enums\BillingItemSourceKind;
use App\Enums\BillingRecordStatus;
use App\Filament\Resources\BillingRecords\BillingRecordResource;
use App\Filament\Resources\BillingRecords\Schemas\ServiceChargeForm;
use App\Models\Patient;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
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

                    ServiceChargeForm::items(),
                    ServiceChargeForm::total(),
                ])
                ->action(function (array $data): void {
                    $patient = Patient::query()->findOrFail($data['patient_id']);

                    try {
                        $items = ServiceChargeForm::normalizeItems($data['items'] ?? []);

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
