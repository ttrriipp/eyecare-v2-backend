<?php

namespace App\Filament\Resources\Appointments\RelationManagers;

use App\Filament\Resources\Billings\BillingResource;
use App\Models\Billing;
use Filament\Actions\ViewAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class BillingsRelationManager extends RelationManager
{
    protected static string $relationship = 'billings';

    protected static ?string $title = 'Billings';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('billing_number')->label('Billing #')->searchable(),
                TextColumn::make('status.name')
                    ->label('Status')
                    ->badge()
                    ->color(fn (Billing $record): string => match ($record->status?->name) {
                        'issued' => 'warning',
                        'partially_paid' => 'info',
                        'paid' => 'success',
                        'voided' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => ucwords(str_replace('_', ' ', $state))),
                TextColumn::make('total_amount')->label('Total')->money('PHP'),
                TextColumn::make('balance_due')->label('Balance Due')->money('PHP'),
                TextColumn::make('issued_at')->label('Issued')->date('M j, Y')->sortable(),
            ])
            ->recordActions([
                ViewAction::make()
                    ->url(fn ($record) => BillingResource::getUrl('view', ['record' => $record])),
            ])
            ->defaultSort('issued_at', 'desc')
            ->paginated(false);
    }
}
