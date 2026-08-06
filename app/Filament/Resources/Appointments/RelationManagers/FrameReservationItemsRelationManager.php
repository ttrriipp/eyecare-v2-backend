<?php

namespace App\Filament\Resources\Appointments\RelationManagers;

use App\Actions\Reservations\AddFrameReservationItem;
use App\Actions\Reservations\CreateFrameReservation;
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

class FrameReservationItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'frameReservationItems';

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
            ->headerActions([
                Action::make('reserveFrames')
                    ->label('Reserve Frames')
                    ->icon('heroicon-o-eye')
                    ->color('info')
                    ->visible(fn (): bool => $this->getOwnerRecord()->status?->name === 'scheduled'
                        && ! $this->getOwnerRecord()->scheduled_at
                            ?->addMinutes($this->getOwnerRecord()->duration_minutes ?? 30)
                            ->isPast()
                        // A reservation is a before-the-visit tool — an appointment gets exactly
                        // one, ever, regardless of status (matches the DB-level unique constraint
                        // on appointment_id). Anything tried on in person after that flows
                        // straight into the sale without another reservation.
                        && $this->getOwnerRecord()->frameReservation === null)
                    ->schema([
                        Select::make('product_variant_ids')
                            ->label('Frames')
                            ->options(fn (): array => ProductVariant::query()
                                ->active()
                                ->whereHas('product', fn ($q) => $q->where('is_active', true)->where('product_type', 'frame'))
                                ->with('product')
                                ->get()
                                ->mapWithKeys(fn (ProductVariant $variant): array => [
                                    $variant->id => "{$variant->product->name} — {$variant->name} ({$variant->sku})",
                                ])
                                ->all())
                            ->multiple()
                            ->searchable()
                            ->preload()
                            ->required(),
                    ])
                    ->action(function (array $data): void {
                        $appointment = $this->getOwnerRecord();

                        try {
                            $reservation = app(CreateFrameReservation::class)->handle(
                                patient: $appointment->patient,
                                appointment: $appointment,
                                items: collect($data['product_variant_ids'])
                                    ->map(fn ($id): array => ['product_variant_id' => (int) $id])
                                    ->all(),
                            );

                            Notification::make()
                                ->title('Frames reserved')
                                ->body("Reservation #{$reservation->id}")
                                ->success()
                                ->send();
                        } catch (ValidationException $e) {
                            $message = collect($e->errors())->flatten()->first() ?? 'Cannot reserve frames.';
                            Notification::make()->title('Cannot reserve frames')->body($message)->danger()->send();
                        }
                    }),

                Action::make('addFrame')
                    ->label('Add Frame')
                    ->icon('heroicon-o-plus')
                    ->color('gray')
                    ->visible(fn (): bool => in_array($this->getOwnerRecord()->frameReservation?->status, [ReservationStatus::Requested, ReservationStatus::Prepared], true))
                    ->schema([
                        Select::make('product_variant_id')
                            ->label('Frame')
                            ->options(fn (): array => ProductVariant::query()
                                ->active()
                                ->whereHas('product', fn ($q) => $q->where('is_active', true)->where('product_type', 'frame'))
                                ->whereNotIn('id', $this->getOwnerRecord()->frameReservation?->items()->pluck('product_variant_id') ?? [])
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
                        $reservation = $this->getOwnerRecord()->frameReservation;

                        try {
                            app(AddFrameReservationItem::class)->handle(
                                reservation: $reservation,
                                productVariantId: (int) $data['product_variant_id'],
                            );

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
            ->defaultSort('created_at', 'desc')
            ->paginated(false)
            ->heading('Reserved Frames');
    }
}
