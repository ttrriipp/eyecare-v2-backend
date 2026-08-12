<?php

namespace App\Filament\Resources\FrameReservations\Schemas;

use App\Models\FrameReservation;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class FrameReservationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Grid::make(3)->schema([
                // ── Main (2/3) ──────────────────────────────────────
                Grid::make(1)->columnSpan(2)->schema([
                    Section::make('Reservation Details')
                        ->schema([
                            Placeholder::make('patient_name')
                                ->label('Patient')
                                ->content(fn (FrameReservation $record): string => $record->patient?->full_name ?? '—'),
                            Placeholder::make('appointment')
                                ->label('Appointment')
                                ->content(fn (FrameReservation $record): string => $record->appointment?->appointment_number ?? '—'),
                            Placeholder::make('held_state')
                                ->label('State')
                                ->content(fn (FrameReservation $record): string => $record->isHeld() ? 'Frames set aside' : 'Awaiting acceptance')
                                ->badge()
                                ->color(fn (FrameReservation $record): string => $record->isHeld() ? 'success' : 'warning'),
                            Textarea::make('staff_notes')
                                ->label('Notes')
                                ->columnSpanFull(),
                        ])
                        ->columns(2),
                ]),

                // ── Sidebar (1/3) ────────────────────────────────────
                Grid::make(1)->columnSpan(1)->schema([
                    Section::make('Timeline')
                        ->schema([
                            Placeholder::make('created_at')
                                ->label('Created')
                                ->content(fn (FrameReservation $record): string => $record->created_at?->diffForHumans() ?? '—'),
                            Placeholder::make('accepted_at')
                                ->label('Accepted')
                                ->content(fn (FrameReservation $record): string => $record->accepted_at?->format('M j, Y g:i A') ?? '—')
                                ->visible(fn (FrameReservation $record): bool => $record->isHeld()),
                            Placeholder::make('updated_at')
                                ->label('Last Updated')
                                ->content(fn (FrameReservation $record): string => $record->updated_at?->diffForHumans() ?? '—'),
                        ]),
                ]),
            ]),
        ]);
    }
}
