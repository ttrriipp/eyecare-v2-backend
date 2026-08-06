<?php

namespace App\Filament\Resources\FrameReservations\Pages;

use App\Actions\Reservations\AddFrameReservationItem;
use App\Actions\Reservations\MarkFrameReservationTriedOn;
use App\Actions\Reservations\PrepareFrameReservation;
use App\Actions\Reservations\ReleaseFrameReservation;
use App\Enums\ReservationStatus;
use App\Filament\Resources\FrameReservations\FrameReservationResource;
use App\Models\ProductVariant;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Validation\ValidationException;

class EditFrameReservation extends EditRecord
{
    protected static string $resource = FrameReservationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('addFrame')
                ->label('Add Frame')
                ->icon('heroicon-o-plus')
                ->color('gray')
                ->visible(fn (): bool => in_array($this->record->status, [ReservationStatus::Requested, ReservationStatus::Prepared], true))
                ->schema([
                    Select::make('product_variant_id')
                        ->label('Frame')
                        ->options(fn (): array => ProductVariant::query()
                            ->active()
                            ->whereHas('product', fn ($q) => $q->where('is_active', true)->where('product_type', 'frame'))
                            ->whereNotIn('id', $this->record->items()->pluck('product_variant_id'))
                            ->with('product')
                            ->get()
                            ->mapWithKeys(fn (ProductVariant $variant): array => [
                                $variant->id => "{$variant->product->name} — {$variant->name} ({$variant->sku})",
                            ])
                            ->all())
                        ->searchable()
                        ->preload()
                        ->required(),
                ])
                ->action(function (array $data): void {
                    try {
                        app(AddFrameReservationItem::class)->handle(
                            reservation: $this->record,
                            productVariantId: (int) $data['product_variant_id'],
                        );

                        Notification::make()->title('Frame added')->success()->send();
                    } catch (ValidationException $e) {
                        $message = collect($e->errors())->flatten()->first() ?? 'Cannot add frame.';
                        Notification::make()->title('Cannot add frame')->body($message)->danger()->send();
                    }
                }),

            Action::make('prepare')
                ->label('Prepare')
                ->icon('heroicon-o-check')
                ->color('info')
                ->visible(fn (): bool => $this->record->status === ReservationStatus::Requested)
                ->requiresConfirmation()
                ->action(function (): void {
                    app(PrepareFrameReservation::class)->handle($this->record);
                    $this->refreshFormData(['status']);
                }),

            Action::make('triedOn')
                ->label('Mark Tried On')
                ->icon('heroicon-o-eye')
                ->color('info')
                ->visible(fn (): bool => $this->record->status === ReservationStatus::Prepared)
                ->requiresConfirmation()
                ->action(function (): void {
                    app(MarkFrameReservationTriedOn::class)->handle($this->record);
                    $this->refreshFormData(['status']);
                }),

            Action::make('release')
                ->label('Release')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('warning')
                ->visible(fn (): bool => in_array($this->record->status, [ReservationStatus::Requested, ReservationStatus::Prepared, ReservationStatus::TriedOn], true))
                ->requiresConfirmation()
                ->action(function (): void {
                    app(ReleaseFrameReservation::class)->handle($this->record);
                    $this->refreshFormData(['status']);
                }),
        ];
    }
}
