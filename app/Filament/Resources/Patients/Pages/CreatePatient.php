<?php

namespace App\Filament\Resources\Patients\Pages;

use App\Filament\Resources\Patients\PatientResource;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreatePatient extends CreateRecord
{
    protected static string $resource = PatientResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('quickCreate')
                ->label('Quick Add Patient')
                ->icon('heroicon-o-user-plus')
                ->color('success')
                ->modalHeading('Quick Add New Patient')
                ->modalDescription('Fill in the patient details below. All fields marked with * are required.')
                ->modalSubmitActionLabel('Create Patient')
                ->schema([
                    TextInput::make('full_name')
                        ->label('Full Name')
                        ->required()
                        ->maxLength(255)
                        ->columnSpanFull(),
                    TextInput::make('phone')
                        ->label('Phone Number')
                        ->tel()
                        ->required()
                        ->maxLength(20),
                    TextInput::make('contact_email')
                        ->label('Email Address')
                        ->email()
                        ->nullable()
                        ->maxLength(255),
                    DatePicker::make('date_of_birth')
                        ->label('Date of Birth')
                        ->nullable()
                        ->maxDate(now())
                        ->displayFormat('M d, Y'),
                    Select::make('gender')
                        ->label('Gender')
                        ->options([
                            'male' => 'Male',
                            'female' => 'Female',
                            'other' => 'Other',
                        ])
                        ->nullable()
                        ->native(false),
                    TextInput::make('occupation')
                        ->label('Occupation')
                        ->nullable()
                        ->maxLength(255),
                    TextInput::make('address')
                        ->label('Address')
                        ->nullable()
                        ->maxLength(500)
                        ->columnSpanFull(),
                ])
                ->action(function (array $data): void {
                    $patient = static::getModel()::create($data);

                    Notification::make()
                        ->title('Patient created successfully')
                        ->success()
                        ->send();

                    $this->redirect(static::getResource()::getUrl('edit', ['record' => $patient]));
                }),
        ];
    }
}
