<?php

namespace App\Filament\Resources\Orders\Pages;

use App\Actions\Orders\UpdateOrderStatus;
use App\Filament\Resources\Billings\BillingResource;
use App\Filament\Resources\Orders\OrderResource;
use App\Models\OrderStatus;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
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

            RestoreAction::make(),
            DeleteAction::make(),
            ForceDeleteAction::make(),
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
                    app(UpdateOrderStatus::class)->handle($record, $newStatusName);
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
        // Redirect to self so relation managers re-render with updated order status
        $this->redirect($this->getResource()::getUrl('edit', ['record' => $this->getRecord()]));
    }
}
