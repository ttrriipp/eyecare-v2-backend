<?php

namespace App\Filament\Resources\ClinicPaymentMethods\Tables;

use App\Enums\OrderPaymentMethod;
use App\Models\ClinicPaymentMethod;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ClinicPaymentMethodsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('method')
                    ->label('Method')
                    ->badge()
                    ->formatStateUsing(fn (mixed $state): string => $state instanceof OrderPaymentMethod
                        ? $state->label()
                        : (OrderPaymentMethod::tryFrom((string) $state)?->label() ?? (string) $state)),
                TextColumn::make('account_name')
                    ->label('Account')
                    ->description(fn (ClinicPaymentMethod $record): string => $record->account_number)
                    ->searchable(),
                TextColumn::make('bank_name')
                    ->label('Bank')
                    ->placeholder('—')
                    ->toggleable(),
                IconColumn::make('qr_image_path')
                    ->label('QR')
                    ->state(fn (ClinicPaymentMethod $record): bool => filled($record->qr_image_path))
                    ->boolean(),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
                TextColumn::make('updated_at')
                    ->label('Updated')
                    ->dateTime('M j, Y g:i A')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
