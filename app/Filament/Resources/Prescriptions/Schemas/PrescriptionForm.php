<?php

namespace App\Filament\Resources\Prescriptions\Schemas;

use App\Filament\Resources\Prescriptions\Pages\AmendPrescription;
use App\Filament\Resources\Prescriptions\Pages\CreatePrescription;
use App\Filament\Resources\Prescriptions\Pages\ViewPrescription;
use App\Models\Appointment;
use App\Models\Encounter;
use App\Models\Patient;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

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
            : Section::make('Patient Information')->schema([
                Select::make('patient_id')
                    ->label('Patient')
                    ->relationship('patient', 'first_name')
                    ->required()
                    ->disabled(fn (mixed $livewire): bool => $livewire instanceof CreatePrescription)
                    ->searchable()
                    ->preload()
                    ->live()
                    ->createOptionForm([
                        TextInput::make('first_name')->required(),
                        TextInput::make('phone')->required()->tel(),
                        TextInput::make('contact_email')->email()->nullable(),
                    ])
                    ->createOptionUsing(fn (array $data): int => Patient::query()->create($data)->getKey()),
                Select::make('appointment_id')
                    ->disabled(fn (mixed $livewire): bool => $livewire instanceof CreatePrescription)
                    ->relationship(
                        'appointment',
                        'id',
                        fn (Builder $query, Get $get): Builder => $query
                            ->when(
                                filled($get('patient_id')),
                                fn (Builder $appointmentQuery): Builder => $appointmentQuery->where('patient_id', $get('patient_id')),
                            ),
                    )
                    ->getOptionLabelFromRecordUsing(
                        fn (Appointment $record): string => "{$record->scheduled_at->format('Y-m-d H:i')} (#{$record->id})",
                    )
                    ->searchable()
                    ->preload(),
            ])->columns(2);

        return array_filter([
            $patientInformation,

            Section::make('Prescription')
                ->schema([
                    Grid::make(3)->schema([
                        TextInput::make('main_od_value')
                            ->label('O.D.')
                            ->numeric()
                            ->step(0.25)
                            ->nullable()
                            ->disabled($disabledForExistingPrescription),
                        TextInput::make('main_od_sphere')
                            ->label('SPH')
                            ->numeric()
                            ->step(0.25)
                            ->nullable()
                            ->disabled($disabledForExistingPrescription),
                        TextInput::make('main_od_cylinder')
                            ->label('CX')
                            ->numeric()
                            ->step(0.25)
                            ->nullable()
                            ->disabled($disabledForExistingPrescription),
                        TextInput::make('main_os_value')
                            ->label('O.S.')
                            ->numeric()
                            ->step(0.25)
                            ->nullable()
                            ->disabled($disabledForExistingPrescription),
                        TextInput::make('main_os_sphere')
                            ->label('SPH')
                            ->numeric()
                            ->step(0.25)
                            ->nullable()
                            ->disabled($disabledForExistingPrescription),
                        TextInput::make('main_os_cylinder')
                            ->label('CX')
                            ->numeric()
                            ->step(0.25)
                            ->nullable()
                            ->disabled($disabledForExistingPrescription),
                    ]),
                ]),

            Section::make('ADD')
                ->schema([
                    Grid::make(3)->schema([
                        TextInput::make('add_od_value')
                            ->label('O.D.')
                            ->numeric()
                            ->step(0.25)
                            ->nullable()
                            ->disabled($disabledForExistingPrescription),
                        TextInput::make('add_od_sphere')
                            ->label('SPH')
                            ->numeric()
                            ->step(0.25)
                            ->nullable()
                            ->disabled($disabledForExistingPrescription),
                        TextInput::make('add_od_cylinder')
                            ->label('CX')
                            ->numeric()
                            ->step(0.25)
                            ->nullable()
                            ->disabled($disabledForExistingPrescription),
                        TextInput::make('add_os_value')
                            ->label('O.S.')
                            ->numeric()
                            ->step(0.25)
                            ->nullable()
                            ->disabled($disabledForExistingPrescription),
                        TextInput::make('add_os_sphere')
                            ->label('SPH')
                            ->numeric()
                            ->step(0.25)
                            ->nullable()
                            ->disabled($disabledForExistingPrescription),
                        TextInput::make('add_os_cylinder')
                            ->label('CX')
                            ->numeric()
                            ->step(0.25)
                            ->nullable()
                            ->disabled($disabledForExistingPrescription),
                    ]),
                ]),

            Section::make('Details')->schema([
                DatePicker::make('prescribed_at')
                    ->label('Date')
                    ->required()
                    ->disabled($forEncounter
                        ? $disabledForExistingPrescription
                        : fn (mixed $livewire): bool => $livewire instanceof CreatePrescription)
                    ->live(onBlur: true),
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
