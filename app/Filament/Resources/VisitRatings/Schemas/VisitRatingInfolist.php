<?php

namespace App\Filament\Resources\VisitRatings\Schemas;

use App\Filament\Resources\Appointments\AppointmentResource;
use App\Filament\Resources\Encounters\EncounterResource;
use App\Filament\Resources\Patients\PatientResource;
use App\Models\VisitRating;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class VisitRatingInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Visit context')
                    ->schema([
                        TextEntry::make('patient.full_name')
                            ->label('Patient')
                            ->placeholder('Patient record unavailable')
                            ->url(fn (VisitRating $record): ?string => $record->patient === null
                                ? null
                                : PatientResource::getUrl('edit', ['record' => $record->patient])),

                        TextEntry::make('appointment.appointment_number')
                            ->label('Appointment')
                            ->placeholder('Appointment unavailable')
                            ->url(fn (VisitRating $record): ?string => $record->appointment === null
                                ? null
                                : AppointmentResource::getUrl('edit', ['record' => $record->appointment])),

                        TextEntry::make('appointment.scheduled_at')
                            ->label('Visit date and time')
                            ->dateTime('M j, Y g:i A')
                            ->placeholder('No visit date'),

                        TextEntry::make('appointment.appointmentType.name')
                            ->label('Visit type')
                            ->placeholder('—'),

                        TextEntry::make('appointment.reason_for_visit')
                            ->label('Reason for visit')
                            ->placeholder('—')
                            ->columnSpanFull(),

                        TextEntry::make('appointment.status.name')
                            ->label('Appointment status')
                            ->badge()
                            ->formatStateUsing(fn (?string $state): ?string => $state === null
                                ? null
                                : ucwords(str_replace('_', ' ', $state)))
                            ->placeholder('—'),

                        TextEntry::make('optometrist.full_name')
                            ->label('Optometrist')
                            ->placeholder('—'),

                        TextEntry::make('encounter.encounter_number')
                            ->label('Consultation')
                            ->placeholder('No consultation linked')
                            ->url(fn (VisitRating $record): ?string => $record->encounter === null
                                ? null
                                : EncounterResource::getUrl('edit', ['record' => $record->encounter])),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),

                Section::make('Patient feedback')
                    ->schema([
                        TextEntry::make('rating')
                            ->label('Rating')
                            ->badge()
                            ->color(fn (int $state): string => match (true) {
                                $state >= 4 => 'success',
                                $state >= 3 => 'warning',
                                default => 'danger',
                            })
                            ->formatStateUsing(fn (int $state): string => "{$state} of 5 stars"),

                        TextEntry::make('comment')
                            ->label('Comment')
                            ->placeholder('No comment provided')
                            ->columnSpanFull(),

                        TextEntry::make('created_at')
                            ->label('Submitted')
                            ->dateTime('M j, Y g:i A'),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
            ]);
    }
}
