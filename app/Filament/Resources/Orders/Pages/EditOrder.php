<?php

namespace App\Filament\Resources\Orders\Pages;

use App\Actions\Orders\UpdateOrderStatus;
use App\Filament\Resources\Billings\BillingResource;
use App\Filament\Resources\Orders\OrderResource;
use App\Models\OrderStatus;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Validation\ValidationException;

class EditOrder extends EditRecord
{
    protected static string $resource = OrderResource::class;

    protected function afterFill(): void
    {
        $record = $this->getRecord();

        if ($record->status->name !== 'requested') {
            return;
        }

        $record->loadMissing('items');
        $unassigned = $record->items->filter(
            fn ($item) => $item->lens_type_id !== null && $item->lens_product_variant_id === null
        )->count();

        if ($unassigned > 0) {
            Notification::make()
                ->title("{$unassigned} item(s) need a lens assigned before confirming")
                ->warning()
                ->persistent()
                ->send();
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('view_billing')
                ->label('View Billing')
                ->icon('heroicon-o-banknotes')
                ->color('success')
                ->visible(fn (): bool => $this->getRecord()->billing !== null)
                ->url(fn (): string => BillingResource::getUrl('view', ['record' => $this->getRecord()->billing])),

            RestoreAction::make()->visible(fn (): bool => (auth()->user()?->isAdmin() ?? false) && $this->getRecord()->trashed()),
            DeleteAction::make()->visible(fn (): bool => (auth()->user()?->isAdmin() ?? false) && ! $this->getRecord()->trashed()),
        ];
    }

    /** @param array<string, mixed> $data */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $record = $this->getRecord();
        $newStatusId = $data['order_status_id'] ?? null;

        if ($newStatusId && (int) $newStatusId !== $record->order_status_id) {
            $newStatusName = OrderStatus::find($newStatusId)?->name;

            if ($newStatusName) {
                try {
                    $discountTypeId = isset($data['discount_type_id']) ? (int) $data['discount_type_id'] : null;
                    $customDiscountAmount = isset($data['custom_discount_amount']) ? (float) $data['custom_discount_amount'] : null;

                    app(UpdateOrderStatus::class)->handle($record, $newStatusName, $discountTypeId, $customDiscountAmount);
                } catch (ValidationException $e) {
                    $message = collect($e->errors())->flatten()->first() ?? 'Cannot change order status.';
                    Notification::make()->title('Status update failed')->body($message)->danger()->send();
                }
            }

            // Always keep DB value in sync (action already updated it, or we keep current)
            $data['order_status_id'] = $record->fresh()->order_status_id;
        }

        return $data;
    }

    protected function afterSave(): void
    {
        // Recalculate order totals from saved items
        $order = $this->getRecord()->fresh(['items']);
        $newSubtotal = $order->items->sum(fn ($i): float => (float) $i->subtotal);
        $newTotal = bcsub(number_format($newSubtotal, 2, '.', ''), (string) $order->discount_amount, 2);
        $order->update(['subtotal' => number_format($newSubtotal, 2, '.', ''), 'total_amount' => $newTotal]);

        // Redirect to self so the form re-renders with updated order status and totals
        $this->redirect($this->getResource()::getUrl('edit', ['record' => $order]));
    }

    public function resetItems(): void
    {
        $this->redirect($this->getResource()::getUrl('edit', ['record' => $this->getRecord()]));
    }
}
