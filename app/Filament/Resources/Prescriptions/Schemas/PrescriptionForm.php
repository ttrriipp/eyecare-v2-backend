<?php

namespace App\Filament\Resources\Prescriptions\Schemas;

use App\Models\Appointment;
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
            ->components([
                Section::make('Patient Information')->schema([
                    Select::make('patient_id')
                        ->label('Patient')
                        ->relationship('patient', 'full_name')
                        ->required()
                        ->searchable()
                        ->preload()
                        ->live()
                        ->createOptionForm([
                            TextInput::make('full_name')->required(),
                            TextInput::make('phone')->required()->tel(),
                            TextInput::make('contact_email')->email()->nullable(),
                        ])
                        ->createOptionUsing(fn (array $data): int => Patient::query()->create($data)->getKey()),
                    Select::make('appointment_id')
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
                ])->columns(2),
                Grid::make(2)->schema([
                    Section::make('Right Eye (OD)')
                        ->schema([
                            Grid::make(3)->schema([
                                TextInput::make('od_sphere')
                                    ->label('Sphere')
                                    ->required()
                                    ->numeric()
                                    ->minValue(-20)
                                    ->maxValue(20)
                                    ->step(0.25),
                                TextInput::make('od_cylinder')
                                    ->label('Cylinder')
                                    ->numeric()
                                    ->minValue(-10)
                                    ->maxValue(10)
                                    ->step(0.25)
                                    ->default(0),
                                TextInput::make('od_axis')
                                    ->label('Axis')
                                    ->numeric()
                                    ->minValue(0)
                                    ->maxValue(180)
                                    ->default(0),
                                TextInput::make('od_add')
                                    ->label('Add')
                                    ->numeric()
                                    ->minValue(0)
                                    ->maxValue(5)
                                    ->step(0.25),
                            ]),
                        ]),
                    Section::make('Left Eye (OS)')
                        ->schema([
                            Grid::make(3)->schema([
                                TextInput::make('os_sphere')
                                    ->label('Sphere')
                                    ->required()
                                    ->numeric()
                                    ->minValue(-20)
                                    ->maxValue(20)
                                    ->step(0.25),
                                TextInput::make('os_cylinder')
                                    ->label('Cylinder')
                                    ->numeric()
                                    ->minValue(-10)
                                    ->maxValue(10)
                                    ->step(0.25)
                                    ->default(0),
                                TextInput::make('os_axis')
                                    ->label('Axis')
                                    ->numeric()
                                    ->minValue(0)
                                    ->maxValue(180)
                                    ->default(0),
                                TextInput::make('os_add')
                                    ->label('Add')
                                    ->numeric()
                                    ->minValue(0)
                                    ->maxValue(5)
                                    ->step(0.25),
                            ]),
                        ]),
                ]),
                Section::make('Prescription Details')->schema([
                    Grid::make(3)->schema([
                        TextInput::make('pd')
                            ->label('PD (mm)')
                            ->required()
                            ->numeric()
                            ->minValue(40)
                            ->maxValue(80)
                            ->step(0.5),
                        DatePicker::make('prescribed_at')
                            ->required()
                            ->live(onBlur: true),
                        DatePicker::make('expires_at')
                            ->required()
                            ->afterOrEqual('prescribed_at'),
                    ]),
                    Textarea::make('notes')
                        ->columnSpanFull(),
                ]),
            ]);
    }
}
