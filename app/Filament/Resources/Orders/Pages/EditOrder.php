<?php

namespace App\Filament\Resources\Orders\Pages;

use App\Actions\Orders\UpdateOrderStatus;
use App\Filament\Resources\Orders\OrderResource;
use App\Models\Order;
use App\Models\OrderStatus;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class EditOrder extends EditRecord
{
    protected static string $resource = OrderResource::class;

    protected bool $statusUpdateHandled = false;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        /** @var Order $order */
        $order = $this->getRecord()->fresh(['status']);

        $newStatusId = (int) ($data['order_status_id'] ?? $order->order_status_id);

        if ($newStatusId === (int) $order->order_status_id) {
            return $data;
        }

        $statusName = OrderStatus::query()->findOrFail($newStatusId)->name;

        try {
            app(UpdateOrderStatus::class)->handle(order: $order, statusName: $statusName);
        } catch (ValidationException $e) {
            $message = collect($e->errors())->flatten()->first() ?? 'Invalid status transition.';
            Notification::make()->title('Cannot update status')->body($message)->danger()->send();

            throw ValidationException::withMessages([
                'data.order_status_id' => [$message],
            ]);
        }

        unset($data['order_status_id']);
        $this->statusUpdateHandled = true;

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $handled = $this->statusUpdateHandled;
        $this->statusUpdateHandled = false;

        if ($handled) {
            if ($data !== []) {
                $record->update($data);
            }

            return $record->fresh(['status', 'customer', 'items']);
        }

        $record->update($data);

        return $record;
    }
}
