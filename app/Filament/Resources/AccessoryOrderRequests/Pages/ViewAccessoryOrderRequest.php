<?php

namespace App\Filament\Resources\AccessoryOrderRequests\Pages;

use App\Actions\AccessoryOrderRequests\AcceptAccessoryOrderRequest;
use App\Actions\AccessoryOrderRequests\AcceptDiscountProof;
use App\Actions\AccessoryOrderRequests\RejectAccessoryOrderRequest;
use App\Actions\AccessoryOrderRequests\RejectDiscountProof;
use App\Enums\DiscountProofStatus;
use App\Filament\Resources\AccessoryOrderRequests\AccessoryOrderRequestResource;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ViewAccessoryOrderRequest extends ViewRecord
{
    protected static string $resource = AccessoryOrderRequestResource::class;

    /**
     * @var array<string, string>
     */
    private const REJECTION_REASON_OPTIONS = [
        'product_unavailable' => 'Product unavailable',
        'out_of_stock' => 'Out of stock',
        'duplicate' => 'Duplicate request',
        'patient_request' => 'Patient request',
        'other' => 'Other',
    ];

    protected function getHeaderActions(): array
    {
        return [
            Action::make('viewDiscountProof')
                ->label('View discount proof')
                ->icon('heroicon-o-identification')
                ->color('gray')
                ->url(fn (): string => route(
                    'discount-proofs.download',
                    ['proof' => $this->record->discountProof],
                ))
                ->openUrlInNewTab()
                ->visible(fn (): bool => $this->canReviewCommerce() && $this->record->discountProof !== null),

            Action::make('acceptDiscountProof')
                ->label('Accept discount proof')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn (): bool => $this->canReviewCommerce()
                    && $this->record->isPending()
                    && $this->record->discountProof?->status === DiscountProofStatus::Pending)
                ->requiresConfirmation()
                ->modalHeading('Accept Discount Proof')
                ->modalDescription('The patient can be accepted for the requested discount after this proof is verified.')
                ->action(function (): void {
                    try {
                        app(AcceptDiscountProof::class)->handle(
                            proof: $this->record->discountProof,
                            reviewer: auth()->user(),
                        );
                    } catch (ValidationException $e) {
                        Notification::make()
                            ->title('Cannot accept discount proof')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();

                        return;
                    }

                    $this->record = $this->record->fresh(['discountProof.reviewedBy']);

                    Notification::make()
                        ->title('Discount proof accepted')
                        ->success()
                        ->send();
                }),

            Action::make('rejectDiscountProof')
                ->label('Reject discount proof')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn (): bool => $this->canReviewCommerce()
                    && $this->record->isPending()
                    && $this->record->discountProof?->status === DiscountProofStatus::Pending)
                ->form([
                    Textarea::make('reason')
                        ->label('Rejection reason')
                        ->required()
                        ->maxLength(1000)
                        ->columnSpanFull(),
                ])
                ->action(function (array $data): void {
                    try {
                        app(RejectDiscountProof::class)->handle(
                            proof: $this->record->discountProof,
                            reviewer: auth()->user(),
                            reason: (string) ($data['reason'] ?? ''),
                        );
                    } catch (ValidationException $e) {
                        Notification::make()
                            ->title('Cannot reject discount proof')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();

                        return;
                    }

                    $this->record = $this->record->fresh(['discountProof.reviewedBy']);

                    Notification::make()
                        ->title('Discount proof rejected')
                        ->success()
                        ->send();
                }),

            Action::make('accept')
                ->label('Accept')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn (): bool => $this->record->isPending()
                    && $this->canReviewCommerce()
                    && ($this->record->requested_discount_type === 'none'
                        || $this->record->discountProof?->status === DiscountProofStatus::Accepted))
                ->requiresConfirmation()
                ->modalHeading('Accept Order Request')
                ->modalDescription('This will create an Optical Order and Billing Record. Stock will be committed.')
                ->form([
                    TextInput::make('discount_amount')
                        ->label('Discount Amount')
                        ->numeric()
                        ->prefix('₱')
                        ->default(0)
                        ->minValue(0),
                ])
                ->action(function (array $data): void {
                    try {
                        $result = app(AcceptAccessoryOrderRequest::class)->handle(
                            orderRequest: $this->record,
                            reviewer: auth()->user(),
                            discountAmount: (float) ($data['discount_amount'] ?? 0),
                        );

                        $this->record = $this->record->fresh();

                        Notification::make()
                            ->title('Request accepted')
                            ->success()
                            ->send();
                    } catch (ValidationException $e) {
                        Notification::make()
                            ->title('Cannot accept')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),

            Action::make('reject')
                ->label('Reject')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn (): bool => $this->record->isPending() && $this->canReviewCommerce())
                ->form([
                    Select::make('reason_category')
                        ->label('Rejection reason')
                        ->options(self::REJECTION_REASON_OPTIONS)
                        ->required()
                        ->live(),
                    Textarea::make('rejection_details')
                        ->label('Details')
                        ->required(fn (Get $get): bool => $get('reason_category') === 'other')
                        ->maxLength(1000)
                        ->columnSpanFull(),
                ])
                ->action(function (array $data): void {
                    try {
                        $category = $data['reason_category'] ?? null;
                        $reason = $category === 'other'
                            ? trim((string) ($data['rejection_details'] ?? ''))
                            : self::REJECTION_REASON_OPTIONS[$category] ?? Str::headline((string) $category);

                        app(RejectAccessoryOrderRequest::class)->handle(
                            orderRequest: $this->record,
                            reviewer: auth()->user(),
                            reason: $reason,
                        );
                    } catch (ValidationException $e) {
                        Notification::make()
                            ->title('Cannot reject')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();

                        return;
                    }

                    $this->record = $this->record->fresh();

                    Notification::make()
                        ->title('Request rejected')
                        ->success()
                        ->send();
                }),
        ];
    }

    private function canReviewCommerce(): bool
    {
        $user = auth()->user();

        return $user !== null
            && $user->is_active
            && ($user->isAdmin() || $user->isStaff());
    }
}
