<?php

namespace App\Filament\Resources\FrameReservations\Schemas;

use App\Enums\ReservationStatus;
use App\Models\FrameReservation;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

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
                            Placeholder::make('status')
                                ->label('Status')
                                ->content(fn (FrameReservation $record): string => Str::headline($record->status->value))
                                ->badge()
                                ->color(fn (FrameReservation $record): string => match ($record->status) {
                                    ReservationStatus::Requested => 'warning',
                                    ReservationStatus::Prepared => 'info',
                                    ReservationStatus::TriedOn => 'primary',
                                    ReservationStatus::Converted => 'success',
                                    ReservationStatus::Released => 'gray',
                                    ReservationStatus::Cancelled => 'danger',
                                }),
                            Textarea::make('staff_notes')
                                ->label('Staff Notes')
                                ->columnSpanFull(),
                        ])
                        ->columns(2),

                    Section::make('Reserved Frames')
                        ->schema([
                            Placeholder::make('items')
                                ->hiddenLabel()
                                ->content(function (FrameReservation $record): string {
                                    $items = $record->items()->with('productVariant.product')->get();

                                    if ($items->isEmpty()) {
                                        return 'No frames reserved.';
                                    }

                                    return $items->map(function ($item): string {
                                        $variant = $item->productVariant;
                                        $product = $variant?->product;

                                        return $product
                                            ? "{$product->name} — {$variant->name} ({$variant->sku})"
                                            : "Variant #{$item->product_variant_id}";
                                    })->implode("\n");
                                })
                                ->columnSpanFull(),
                        ]),
                ]),

                // ── Sidebar (1/3) ────────────────────────────────────
                Grid::make(1)->columnSpan(1)->schema([
                    Section::make('Timeline')
                        ->schema([
                            Placeholder::make('created_at')
                                ->label('Created')
                                ->content(fn (FrameReservation $record): string => $record->created_at?->diffForHumans() ?? '—'),
                            Placeholder::make('expires_at')
                                ->label('Expires')
                                ->content(fn (FrameReservation $record): string => $record->expires_at?->format('M j, Y g:i A') ?? '—'),
                            Placeholder::make('updated_at')
                                ->label('Last Updated')
                                ->content(fn (FrameReservation $record): string => $record->updated_at?->diffForHumans() ?? '—'),
                        ]),
                ]),
            ]),
        ]);
    }
}
