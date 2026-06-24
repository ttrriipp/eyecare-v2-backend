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
}
