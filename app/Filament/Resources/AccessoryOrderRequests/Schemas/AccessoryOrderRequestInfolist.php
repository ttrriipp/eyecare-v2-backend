<?php

namespace App\Filament\Resources\AccessoryOrderRequests\Schemas;

use App\Enums\AccessoryOrderRequestStatus;
use App\Enums\DiscountProofStatus;
use App\Filament\Resources\AccessoryOrderRequests\AccessoryOrderRequestResource;
use App\Models\AccessoryOrderRequest;
use Filament\Infolists\Components\ImageEntry;
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
        $hasAppliedDiscount = fn (AccessoryOrderRequest $record): bool => $record->status === AccessoryOrderRequestStatus::Accepted
            && $record->jobOrder?->activeBillingRecord !== null
            && (float) $record->jobOrder->activeBillingRecord->discount_amount > 0;

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
                TextEntry::make('jobOrder.activeBillingRecord.discount_amount')
                    ->label('Discount amount')
                    ->money('PHP')
                    ->visible($hasAppliedDiscount),
                TextEntry::make('jobOrder.activeBillingRecord.total_amount')
                    ->label('Order total')
                    ->money('PHP')
                    ->visible($hasAppliedDiscount),
            ])
            ->columns(2);

        $isResolved = fn (AccessoryOrderRequest $record): bool => $record->resolved_at !== null;

        $resolutionDetails = Section::make('Resolution details')
            ->schema([
                TextEntry::make('created_at')
                    ->label('Submitted')
                    ->dateTime('M j, Y g:i A'),
                TextEntry::make('encrypted_cancellation_reason')
                    ->label('Patient cancellation reason')
                    ->placeholder('—')
                    ->columnSpanFull()
                    ->visible(fn (AccessoryOrderRequest $record): bool => $record->status === AccessoryOrderRequestStatus::Cancelled
                        && filled($record->encrypted_cancellation_reason)),
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
                    ->visible(fn (AccessoryOrderRequest $record): bool => $record->status === AccessoryOrderRequestStatus::Rejected
                        && filled($record->rejection_reason)),
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
                ImageEntry::make('discount_proof_preview')
                    ->label('Proof attachment')
                    ->state(fn (AccessoryOrderRequest $record): ?string => $record->discountProof === null
                        ? null
                        : route('discount-proofs.preview', ['proof' => $record->discountProof]))
                    ->imageHeight(360)
                    ->extraImgAttributes([
                        'alt' => 'Submitted discount proof',
                        'loading' => 'lazy',
                    ])
                    ->columnSpanFull()
                    ->visible(fn (AccessoryOrderRequest $record): bool => $record->discountProof !== null),
                TextEntry::make('discountProof.reviewedBy.full_name')
                    ->label('Reviewed by')
                    ->placeholder('Awaiting review'),
                TextEntry::make('discountProof.rejection_reason')
                    ->label('Rejection reason')
                    ->placeholder('—')
                    ->columnSpanFull()
                    ->visible(fn (AccessoryOrderRequest $record): bool => $record->discountProof?->status === DiscountProofStatus::Rejected
                        && filled($record->discountProof->rejection_reason)),
            ])
            ->visible(fn (AccessoryOrderRequest $record): bool => $record->requested_discount_type !== 'none')
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
