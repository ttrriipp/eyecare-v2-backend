<?php

namespace App\Livewire;

use App\Filament\Resources\AppointmentRequests\Tables\AppointmentRequestsTable;
use App\Models\AppointmentRequest;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class AppointmentRequestsInlineTable extends TableWidget
{
    protected function getTableQuery(): Builder
    {
        return AppointmentRequest::query()->orderBy('created_at', 'desc');
    }

    public function table(Table $table): Table
    {
        return AppointmentRequestsTable::configure($table);
    }
}
