<?php

namespace App\Filament\Resources\Patients\RelationManagers;

use App\Enums\BillingRecordStatus;
use App\Enums\JobOrderStatus;
use App\Filament\Resources\OpticalOrders\OpticalOrderResource;
use Filament\Actions\ViewAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class OpticalOrdersRelationManager extends RelationManager
{
    protected static string $relationship = 'jobOrders';

    protected static ?string $title = 'Optical Orders';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('job_order_number')->label('Order #')->searchable()->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (JobOrderStatus $state): string => match ($state) {
                        JobOrderStatus::Queued => 'Confirmed',
                        JobOrderStatus::InProgress => 'Processing',
                        JobOrderStatus::ReadyForDispensing => 'Ready for Pickup',
                        JobOrderStatus::Dispensed => 'Completed',
                        JobOrderStatus::Cancelled => 'Cancelled',
                    })
                    ->color(fn (JobOrderStatus $state): string => match ($state) {
                        JobOrderStatus::Queued => 'warning',
                        JobOrderStatus::InProgress => 'primary',
                        JobOrderStatus::ReadyForDispensing => 'success',
                        JobOrderStatus::Dispensed => 'success',
                        JobOrderStatus::Cancelled => 'danger',
                    }),
                TextColumn::make('total_amount')->label('Total')->money('PHP')->sortable(),
                TextColumn::make('billingRecord.status')
                    ->label('Payment')
                    ->badge()
                    ->color(fn (?BillingRecordStatus $state): string => match ($state) {
                        BillingRecordStatus::Paid => 'success',
                        BillingRecordStatus::PartiallyPaid => 'warning',
                        BillingRecordStatus::Unpaid => 'danger',
                        BillingRecordStatus::Voided => 'gray',
                        default => 'gray',
                    })
                    ->placeholder('—'),
                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime('M j, Y g:i A')
                    ->sortable(),
            ])
            ->recordActions([
                ViewAction::make()
                    ->url(fn ($record) => OpticalOrderResource::getUrl('edit', ['record' => $record])),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginated(false);
    }
}
