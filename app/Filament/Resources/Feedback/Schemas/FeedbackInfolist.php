<?php

namespace App\Filament\Resources\Feedback\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class FeedbackInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Grid::make(3)->schema([
                    Section::make('Feedback Details')
                        ->columnSpan(2)
                        ->columns(2)
                        ->schema([
                            TextEntry::make('patient.full_name')
                                ->label('Patient'),
                            TextEntry::make('rating')
                                ->label('Rating')
                                ->badge()
                                ->color(fn (int $state): string => match (true) {
                                    $state >= 4 => 'success',
                                    $state === 3 => 'warning',
                                    default => 'danger',
                                }),
                            TextEntry::make('appointment.appointment_number')
                                ->label('Appointment')
                                ->default('—'),
                            TextEntry::make('comment')
                                ->label('Comment')
                                ->placeholder('—')
                                ->columnSpanFull(),
                        ]),

                    Section::make('Timestamps')
                        ->columnSpan(1)
                        ->schema([
                            TextEntry::make('created_at')
                                ->label('Submitted')
                                ->dateTime('M j, Y g:i A'),
                            TextEntry::make('updated_at')
                                ->label('Last updated')
                                ->dateTime('M j, Y g:i A'),
                        ]),
                ]),
            ]);
    }
}
