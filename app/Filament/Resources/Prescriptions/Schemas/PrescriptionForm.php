<?php

namespace App\Filament\Resources\Prescriptions\Schemas;

use App\Filament\Resources\Prescriptions\Pages\AmendPrescription;
use App\Filament\Resources\Prescriptions\Pages\ViewPrescription;
use App\Models\Encounter;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class PrescriptionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components(self::components());
    }

    /**
     * @return array<int, mixed>
     */
    public static function components(bool $forEncounter = false): array
    {
        $disabledForExistingPrescription = $forEncounter
            ? fn (Encounter $record): bool => $record->prescriptions()->withTrashed()->exists()
                || auth()->user()?->hasOptometristCapability() !== true
            : false;

        $patientInformation = $forEncounter
            ? null
            : null;

        return array_filter([
            $patientInformation,

            Section::make('Prescription')
                ->schema([
                    Grid::make(3)->schema([
                        Placeholder::make('main_od_value')
                            ->label('O.D.')
                            ->content(fn ($record) => $record->main_od_value ?? '—'),
                        Placeholder::make('main_od_sphere')
                            ->label('SPH')
                            ->content(fn ($record) => $record->main_od_sphere ?? '—'),
                        Placeholder::make('main_od_cylinder')
                            ->label('CX')
                            ->content(fn ($record) => $record->main_od_cylinder ?? '—'),
                    ]),

                    Grid::make(3)->schema([
                        Placeholder::make('main_os_value')
                            ->label('O.S.')
                            ->content(fn ($record) => $record->main_os_value ?? '—'),
                        Placeholder::make('main_os_sphere')
                            ->label('SPH')
                            ->content(fn ($record) => $record->main_os_sphere ?? '—'),
                        Placeholder::make('main_os_cylinder')
                            ->label('CX')
                            ->content(fn ($record) => $record->main_os_cylinder ?? '—'),
                    ]),
                ]),

            Section::make('ADD')
                ->schema([
                    Grid::make(3)->schema([
                        Placeholder::make('add_od_value')
                            ->label('O.D.')
                            ->content(fn ($record) => $record->add_od_value ?? '—'),
                        Placeholder::make('add_od_sphere')
                            ->label('SPH')
                            ->content(fn ($record) => $record->add_od_sphere ?? '—'),
                        Placeholder::make('add_od_cylinder')
                            ->label('CX')
                            ->content(fn ($record) => $record->add_od_cylinder ?? '—'),
                    ]),

                    Grid::make(3)->schema([
                        Placeholder::make('add_os_value')
                            ->label('O.S.')
                            ->content(fn ($record) => $record->add_os_value ?? '—'),
                        Placeholder::make('add_os_sphere')
                            ->label('SPH')
                            ->content(fn ($record) => $record->add_os_sphere ?? '—'),
                        Placeholder::make('add_os_cylinder')
                            ->label('CX')
                            ->content(fn ($record) => $record->add_os_cylinder ?? '—'),
                    ]),
                ]),

            Section::make('Details')->schema([
                Textarea::make('remarks')
                    ->label('Remarks')
                    ->disabled($disabledForExistingPrescription)
                    ->columnSpanFull(),
                ...($forEncounter ? [] : [
                    Textarea::make('amendment_reason')
                        ->label('Reason for Amendment')
                        ->helperText('Explain why this finalized prescription is being corrected. The original remains unchanged.')
                        ->required()
                        ->maxLength(1000)
                        ->visible(fn (mixed $livewire): bool => $livewire instanceof AmendPrescription
                            || ($livewire instanceof ViewPrescription
                                && $livewire->getRecord()->previous_prescription_id !== null))
                        ->columnSpanFull(),
                ]),
            ]),
        ]);
    }
}
