<?php

namespace App\Filament\Resources\AccessoryOrderRequests\Schemas;

use App\Enums\AccessoryOrderRequestStatus;
use App\Enums\DiscountProofStatus;
use App\Filament\Resources\AccessoryOrderRequests\AccessoryOrderRequestResource;
use App\Models\AccessoryOrderRequest;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class AccessoryOrderRequestInfolist
{
    public static function configure(Schema $schema): Schema
    {
        $requestDetails = Section::make('Request details')
            ->schema([
                TextEntry::make('request_number')
                    ->label('Request #')
                    ->copyable()
                    ->weight('bold')
                    ->url(fn (AccessoryOrderRequest $record): string => AccessoryOrderRequestResource::getUrl('view', [
                        'record' => $record,
                    ])),
                TextEntry::make('status')
                    ->badge()
                    ->formatStateUsing(fn (AccessoryOrderRequestStatus $state): string => Str::headline($state->value))
                    ->color(fn (AccessoryOrderRequestStatus $state): string => match ($state) {
                        AccessoryOrderRequestStatus::Pending => 'warning',
                        AccessoryOrderRequestStatus::Accepted => 'success',
                        AccessoryOrderRequestStatus::Rejected => 'danger',
                        AccessoryOrderRequestStatus::Cancelled => 'gray',
                    }),
                TextEntry::make('patient.full_name')
                    ->label('Patient')
                    ->placeholder('Patient record unavailable')
                    ->weight('bold'),
                TextEntry::make('patient.patient_number')
                    ->label('Patient #')
                    ->placeholder('—'),
                TextEntry::make('requested_discount_type')
                    ->label('Requested discount')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => Str::headline($state)),
                TextEntry::make('subtotal_amount')
                    ->label('Subtotal')
                    ->money('PHP'),
            ])
            ->columns(2);

        $isResolved = fn (AccessoryOrderRequest $record): bool => $record->resolved_at !== null;

        $resolutionDetails = Section::make('Resolution details')
            ->schema([
                TextEntry::make('created_at')
                    ->label('Submitted')
                    ->dateTime('M j, Y g:i A'),
                TextEntry::make('resolvedBy.full_name')
                    ->label('Resolved by')
                    ->placeholder('Awaiting review')
                    ->visible($isResolved),
                TextEntry::make('resolved_at')
                    ->label('Resolved at')
                    ->dateTime('M j, Y g:i A')
                    ->placeholder('—')
                    ->visible($isResolved),
                TextEntry::make('rejection_reason')
                    ->label('Rejection reason')
                    ->placeholder('—')
                    ->columnSpanFull()
                    ->visible($isResolved),
            ])
            ->columns(2);

        $discountProofDetails = Section::make('Discount proof')
            ->schema([
                TextEntry::make('discount_proof_status')
                    ->label('Status')
                    ->state(fn (AccessoryOrderRequest $record): string => $record->requested_discount_type === 'none'
                        ? 'not_required'
                        : ($record->discountProof?->status?->value ?? 'not_submitted'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => Str::headline($state))
                    ->color(fn (string $state): string => match ($state) {
                        'accepted' => 'success',
                        'rejected' => 'danger',
                        'pending' => 'warning',
                        default => 'gray',
                    }),
                TextEntry::make('discountProof.reviewedBy.full_name')
                    ->label('Reviewed by')
                    ->placeholder('Awaiting review'),
                TextEntry::make('discountProof.rejection_reason')
                    ->label('Rejection reason')
                    ->placeholder('—')
                    ->columnSpanFull()
                    ->visible(fn (AccessoryOrderRequest $record): bool => $record->discountProof?->status === DiscountProofStatus::Rejected),
            ])
            ->columns(2);

        $orderItems = Section::make('Order items')
            ->schema([
                RepeatableEntry::make('items')
                    ->state(fn (AccessoryOrderRequest $record) => $record->items->values())
                    ->table([
                        TableColumn::make('Description'),
                        TableColumn::make('Quantity'),
                        TableColumn::make('Unit Price'),
                        TableColumn::make('Amount'),
                    ])
                    ->schema([
                        TextEntry::make('description')
                            ->hiddenLabel()
                            ->wrap(),
                        TextEntry::make('quantity')
                            ->hiddenLabel(),
                        TextEntry::make('unit_price')
                            ->hiddenLabel()
                            ->money('PHP'),
                        TextEntry::make('amount')
                            ->hiddenLabel()
                            ->money('PHP'),
                    ])
                    ->placeholder('No items recorded.')
                    ->columnSpanFull(),
            ])
            ->columnSpanFull();

        return $schema
            ->columns(1)
            ->components([
                Grid::make(['default' => 1, 'lg' => 3])->schema([
                    $requestDetails->columnSpan(['default' => 1, 'lg' => 2]),
                    $resolutionDetails->columnSpan(['default' => 1, 'lg' => 1]),
                ]),
                $discountProofDetails,
                $orderItems,
            ]);
    }
}
