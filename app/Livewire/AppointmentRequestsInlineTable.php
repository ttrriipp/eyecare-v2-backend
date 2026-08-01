<?php

namespace App\Livewire;

use App\Filament\Resources\AppointmentRequests\Tables\AppointmentRequestsTable;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class AppointmentRequestsInlineTable extends Component
{
    public function table(Table $table): Table
    {
        return AppointmentRequestsTable::configure($table);
    }

    public function render(): View
    {
        return view('livewire.appointment-requests-inline-table');
    }
}
