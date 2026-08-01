<?php

namespace App\Livewire;

use App\Filament\Resources\AppointmentRequests\Tables\AppointmentRequestsTable;
use App\Models\AppointmentRequest;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Component;

class AppointmentRequestsInlineTable extends Component implements HasTable
{
    use InteractsWithTable;

    protected function getTableQuery(): Builder
    {
        return AppointmentRequest::query()->orderBy('created_at', 'desc');
    }

    public function table(Table $table): Table
    {
        return AppointmentRequestsTable::configure($table);
    }

    public function render(): View
    {
        return view('livewire.appointment-requests-inline-table');
    }
}
