<?php

namespace App\Filament\Resources\Billings\Pages;

use App\Filament\Resources\Billings\BillingResource;
use Filament\Resources\Pages\CreateRecord;

class CreateBilling extends CreateRecord
{
    protected static string $resource = BillingResource::class;
}
