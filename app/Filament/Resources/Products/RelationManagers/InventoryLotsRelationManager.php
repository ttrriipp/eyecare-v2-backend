<?php

namespace App\Filament\Resources\Products\RelationManagers;

use App\Models\InventoryLot;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class InventoryLotsRelationManager extends RelationManager
{
    protected static string $relationship = 'lots';

    protected static ?string $title = 'Inventory Lots';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('lot_number')
                ->label('Lot Number')
                ->required()
                ->maxLength(50),
            DatePicker::make('expires_on')
                ->label('Expires On')
                ->required()
                ->native(false),
            TextInput::make('quantity_on_hand')
                ->label('Quantity')
                ->required()
                ->numeric()
                ->minValue(0),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('lot_number')
                    ->label('Lot #')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('expires_on')
                    ->label('Expires')
                    ->date('M d, Y')
                    ->sortable()
                    ->color(fn (InventoryLot $record): string => match (true) {
                        $record->isExpired() => 'danger',
                        $record->expires_on->diffInDays(now()) <= 30 => 'warning',
                        default => 'success',
                    }),
                TextColumn::make('quantity_on_hand')
                    ->label('Qty')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('received_at')
                    ->label('Received')
                    ->dateTime('M d, Y')
                    ->sortable(),
                TextColumn::make('receivedBy.name')
                    ->label('Received By'),
                TextColumn::make('source_reference')
                    ->label('Source')
                    ->toggleable(),
            ])
            ->filters([
                Filter::make('expired')
                    ->query(fn (Builder $query): Builder => $query->expired())
                    ->label('Expired Only')
                    ->toggle(),
                Filter::make('near_expiry')
                    ->query(fn (Builder $query): Builder => $query->where('expires_on', '<=', now()->addDays(30))->where('expires_on', '>', now()))
                    ->label('Near Expiry (30 days)')
                    ->toggle(),
            ])
            ->headerActions([
                CreateAction::make(),
            ])
            ->actions([
                EditAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function canViewFor(string $ownerRelationship, Model $ownerRecord): bool
    {
        // Only show for contact-lens products
        return $ownerRecord->product_type === 'contact_lens';
    }
}
