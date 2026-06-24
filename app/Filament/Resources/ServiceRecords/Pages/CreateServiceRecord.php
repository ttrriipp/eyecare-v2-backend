<?php

namespace App\Filament\Resources\ServiceRecords\Pages;

use App\Actions\Billing\GenerateBillingForService;
use App\Filament\Resources\ServiceRecords\ServiceRecordResource;
use App\Models\ServiceRecord;
use Filament\Resources\Pages\CreateRecord;

class CreateServiceRecord extends CreateRecord
{
    protected static string $resource = ServiceRecordResource::class;

    protected function afterCreate(): void
    {
        /** @var ServiceRecord $record */
        $record = $this->getRecord();

        app(GenerateBillingForService::class)->handle($record);
    }
}
