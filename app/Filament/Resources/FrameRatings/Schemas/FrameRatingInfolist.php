<?php

namespace App\Filament\Resources\FrameRatings\Schemas;

use App\Filament\Resources\Patients\PatientResource;
use App\Filament\Resources\Products\ProductResource;
use App\Models\FrameRating;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class FrameRatingInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Grid::make(['default' => 1, 'lg' => 3])->schema([
                    Section::make('Product context')
                        ->schema([
                            TextEntry::make('patient.full_name')
                                ->label('Patient')
                                ->placeholder('Patient record unavailable')
                                ->weight('bold')
                                ->url(fn (FrameRating $record): ?string => $record->patient === null
                                    ? null
                                    : PatientResource::getUrl('edit', ['record' => $record->patient])),

                            TextEntry::make('variant.product.name')
                                ->label('Product')
                                ->placeholder('Product unavailable')
                                ->weight('bold')
                                ->url(fn (FrameRating $record): ?string => $record->variant?->product === null
                                    ? null
                                    : ProductResource::getUrl('edit', ['record' => $record->variant->product])),

                            TextEntry::make('variant.name')
                                ->label('Variant')
                                ->placeholder('Variant unavailable'),

                            TextEntry::make('variant.sku')
                                ->label('SKU')
                                ->placeholder('—'),
                        ])
                        ->columns(2)
                        ->columnSpan(['default' => 1, 'lg' => 2]),

                    Section::make('Product feedback')
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

                            TextEntry::make('public_display_consent_at')
                                ->label('Public comment display consent')
                                ->state(fn (FrameRating $record): string => $record->public_display_consent_at === null
                                    ? 'Not granted'
                                    : 'Granted on '.$record->public_display_consent_at->format('M j, Y g:i A')),

                            TextEntry::make('public_attachment_consent_at')
                                ->label('Public photo display consent')
                                ->state(fn (FrameRating $record): string => match (true) {
                                    blank($record->attachment_path) => 'No photo attached',
                                    $record->public_attachment_consent_at === null => 'Not granted',
                                    default => 'Granted on '.$record->public_attachment_consent_at->format('M j, Y g:i A'),
                                }),

                            TextEntry::make('created_at')
                                ->label('Submitted')
                                ->dateTime('M j, Y g:i A'),
                        ])
                        ->columns(1)
                        ->columnSpan(['default' => 1, 'lg' => 1]),
                ]),
            ]);
    }
}
