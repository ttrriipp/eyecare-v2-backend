<?php

namespace App\Filament\Resources\ServiceRecords\Pages;

use App\Actions\Billing\GenerateBillingForService;
use App\Filament\Resources\ServiceRecords\ServiceRecordResource;
use App\Models\ServiceRecord;
use Filament\Resources\Pages\CreateRecord;

class CreateServiceRecord extends CreateRecord
{
    protected static string $resource = ServiceRecordResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Ensure discount_amount and total_amount fall back to amount if not set
        if (empty($data['discount_amount'])) {
            $data['discount_amount'] = '0.00';
        }

        if (empty($data['total_amount'])) {
            $data['total_amount'] = $data['amount'] ?? '0.00';
        }

        return $data;
    }

    protected function afterCreate(): void
    {
        /** @var ServiceRecord $record */
        $record = $this->getRecord();

        app(GenerateBillingForService::class)->handle($record);
    }

    protected function getFillableFromRequest(): array
    {
        return array_filter([
            'customer_id' => request()->query('customer_id'),
            'appointment_id' => request()->query('appointment_id'),
            'staff_id' => request()->query('staff_id'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getFormDefaults(): array
    {
        return $this->getFillableFromRequest();
    }
}
