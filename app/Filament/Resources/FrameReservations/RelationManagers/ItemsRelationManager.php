<?php

namespace App\Filament\Resources\FrameReservations\RelationManagers;

use App\Actions\Reservations\AddFrameReservationItem;
use App\Actions\Reservations\RemoveFrameReservationItem;
use App\Enums\ReservationStatus;
use App\Filament\Resources\Products\ProductResource;
use App\Models\FrameReservation;
use App\Models\FrameReservationItem;
use App\Models\ProductVariant;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;

class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = 'Reserved Frames';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Placeholder::make('product_variant')
                ->label('Frame')
                ->content(fn (?FrameReservationItem $record): string => $record?->variant?->product
                    ? "{$record->variant->product->name} — {$record->variant->name} ({$record->variant->sku})"
                    : '—'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->headerActions([
                Action::make('addFrame')
                    ->label('Add Frame')
                    ->icon('heroicon-o-plus')
                    ->color('primary')
                    ->visible(fn (): bool => in_array($this->getOwnerRecord()->status, [ReservationStatus::Requested, ReservationStatus::Prepared], true))
                    ->schema([
                        Select::make('product_variant_id')
                            ->label('Frame')
                            ->options(fn (): array => ProductVariant::query()
                                ->active()
                                ->whereHas('product', fn ($q) => $q->where('is_active', true)->where('product_type', 'frame'))
                                ->whereNotIn('id', $this->getOwnerRecord()->items()->pluck('product_variant_id'))
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
                        /** @var FrameReservation $reservation */
                        $reservation = $this->getOwnerRecord();

                        try {
                            app(AddFrameReservationItem::class)->handle(
                                reservation: $reservation,
                                productVariantId: (int) $data['product_variant_id'],
                            );

                            $reservation->refresh();
                            $this->dispatch('frame-reservation-updated');
                            Notification::make()->title('Frame added')->success()->send();
                        } catch (ValidationException $e) {
                            $message = collect($e->errors())->flatten()->first() ?? 'Cannot add frame.';
                            Notification::make()->title('Cannot add frame')->body($message)->danger()->send();
                        }
                    }),
            ])
            ->columns([
                TextColumn::make('variant.product.name')
                    ->label('Product')
                    ->sortable()
                    ->url(fn (FrameReservationItem $record): string => $record->variant?->product
                        ? ProductResource::getUrl('edit', ['record' => $record->variant->product])
                        : '#')
                    ->openUrlInNewTab(),

                TextColumn::make('variant.name')
                    ->label('Variant')
                    ->sortable(),

                TextColumn::make('variant.sku')
                    ->label('SKU')
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('Reserved')
                    ->dateTime('M j, Y g:i A')
                    ->sortable(),
            ])
            ->recordActions([
                Action::make('removeFrame')
                    ->label('Remove')
                    ->icon('heroicon-o-x-mark')
                    ->color('danger')
                    ->visible(fn (): bool => in_array($this->getOwnerRecord()->status, [ReservationStatus::Requested, ReservationStatus::Prepared], true))
                    ->requiresConfirmation()
                    ->action(function (FrameReservationItem $record): void {
                        /** @var FrameReservation $reservation */
                        $reservation = $this->getOwnerRecord();

                        try {
                            app(RemoveFrameReservationItem::class)->handle($reservation, $record);

                            $reservation->refresh();
                            $this->dispatch('frame-reservation-updated');
                            Notification::make()->title('Frame removed')->success()->send();
                        } catch (ValidationException $e) {
                            $message = collect($e->errors())->flatten()->first() ?? 'Cannot remove frame.';
                            Notification::make()->title('Cannot remove frame')->body($message)->danger()->send();
                        }
                    }),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginated(false)
            ->emptyStateHeading('No frames reserved')
            ->emptyStateDescription('Add the first frame to this reservation.')
            ->heading('Reserved Frames');
    }
}
