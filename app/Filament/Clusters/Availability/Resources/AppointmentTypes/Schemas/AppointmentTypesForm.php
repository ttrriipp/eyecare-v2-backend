<?php

namespace App\Filament\Clusters\Availability\Resources\AppointmentTypes\Schemas;

use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class AppointmentTypesForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Grid::make(['default' => 1, 'lg' => 2])
                    ->columnSpan(['default' => 'full', 'lg' => 'full'])
                    ->schema([
                        Section::make('Basic Information')
                            ->schema([
                                TextInput::make('name')
                                    ->label('Internal Name')
                                    ->required()
                                    ->maxLength(255)
                                    ->unique(ignoreRecord: true),

                                TextInput::make('patient_label')
                                    ->label('Patient Label')
                                    ->maxLength(255),

                                TextInput::make('patient_description')
                                    ->label('Patient Description')
                                    ->maxLength(65535)
                                    ->columnSpanFull()
                                    ->columnSpan(['lg' => 2]),
                            ])
                            ->columns(['default' => 1, 'lg' => 2])
                            ->columnSpan(1),

                        Section::make('Scheduling')
                            ->schema([
                                TextInput::make('duration_minutes')
                                    ->label('Default Duration (minutes)')
                                    ->required()
                                    ->numeric()
                                    ->minValue(5)
                                    ->maxValue(240)
                                    ->step(5)
                                    ->default(30),

                                Toggle::make('requires_referral')
                                    ->label('Requires Referral Source')
                                    ->default(false),

                                Toggle::make('is_patient_visible')
                                    ->label('Patient Visible')
                                    ->default(true),

                                Toggle::make('is_active')
                                    ->label('Active')
                                    ->default(true),
                            ])
                            ->columns(['default' => 1, 'lg' => 2])
                            ->columnSpan(1),

                        Section::make('Visit Reason Presets')
                            ->description('Add common reasons patients may choose after selecting this appointment type. The mobile app provides "Other" separately.')
                            ->schema([
                                Repeater::make('visit_reason_presets')
                                    ->label('Presets')
                                    ->relationship('visitReasonPresets')
                                    ->schema([
                                        TextInput::make('label')
                                            ->label('Reason')
                                            ->trim()
                                            ->required()
                                            ->minLength(1)
                                            ->maxLength(255),

                                        Toggle::make('is_active')
                                            ->label('Active')
                                            ->default(true),
                                    ])
                                    ->columns(['default' => 1, 'lg' => 2])
                                    ->reorderable()
                                    ->orderColumn('sort_order')
                                    ->addActionLabel('Add preset')
                                    ->columnSpanFull(),
                            ])
                            ->columnSpan(['default' => 'full', 'lg' => 2]),
                    ]),
            ]);
    }
}
