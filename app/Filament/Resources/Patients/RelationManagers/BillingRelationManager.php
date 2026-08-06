<?php

namespace App\Filament\Resources\Patients\RelationManagers;

use App\Enums\BillingRecordStatus;
use App\Filament\Resources\BillingRecords\BillingRecordResource;
use App\Models\BillingRecord;
use Filament\Actions\ViewAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class BillingRelationManager extends RelationManager
{
    protected static string $relationship = 'billingRecords';

    protected static ?string $title = 'Billing';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('billing_record_number')->label('Billing #')->searchable()->sortable(),
                TextColumn::make('total_amount')->label('Total')->money('PHP')->sortable(),
                TextColumn::make('amount_paid')->label('Paid')->money('PHP'),
                TextColumn::make('balance_due')
                    ->label('Balance')
                    ->money('PHP')
                    ->color(fn (BillingRecord $record): string => match (true) {
                        (float) $record->balance_due <= 0 => 'success',
                        $record->isOverdue() => 'danger',
                        default => 'warning',
                    }),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (BillingRecordStatus $state): string => match ($state) {
                        BillingRecordStatus::Unpaid => 'gray',
                        BillingRecordStatus::PartiallyPaid => 'warning',
                        BillingRecordStatus::Paid => 'success',
                        BillingRecordStatus::Voided => 'danger',
                    }),
                TextColumn::make('recorded_at')
                    ->label('Recorded')
                    ->dateTime('M j, Y g:i A')
                    ->sortable(),
            ])
            ->recordActions([
                ViewAction::make()
                    ->url(fn ($record) => BillingRecordResource::getUrl('edit', ['record' => $record])),
            ])
            ->defaultSort('recorded_at', 'desc')
            ->paginated(false);
    }
}
