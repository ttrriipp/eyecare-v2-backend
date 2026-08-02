<?php

namespace App\Filament\Resources\OpticalOrders\Pages;

use App\Actions\Quotations\UpdateQuotationDraft;
use App\Enums\QuotationStatus;
use App\Filament\Resources\OpticalOrders\OpticalOrderResource;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class EditOpticalOrder extends EditRecord
{
    protected static string $resource = OpticalOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->visible(fn () => $this->record->status === QuotationStatus::Draft),

            Actions\RestoreAction::make(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        // Load items for the form
        $data['items'] = $this->record->items->map(fn ($item) => [
            'id' => $item->id,
            'description' => $item->description,
            'quantity' => $item->quantity,
            'unit_price' => $item->unit_price,
            'amount' => $item->amount,
            'product_variant_id' => $item->product_variant_id,
            'lens_category_id' => $item->lens_category_id,
        ])->toArray();

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Calculate subtotal from items
        $subtotal = collect($data['items'] ?? [])
            ->sum(fn (array $item) => (float) ($item['amount'] ?? 0));

        $data['subtotal'] = $subtotal;
        $data['discount_amount'] = $data['discount_amount'] ?? 0;
        $data['total'] = max($subtotal - (float) ($data['discount_amount'] ?? 0), 0);

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        try {
            return app(UpdateQuotationDraft::class)->handle($record, $data);
        } catch (ValidationException $e) {
            Notification::make()
                ->title('Cannot update optical order')
                ->body($e->getMessage())
                ->danger()
                ->send();

            $this->halt();
        }
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'Optical order updated';
    }
}
