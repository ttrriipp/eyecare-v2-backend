<?php

namespace App\Filament\Resources\Orders\Pages;

use App\Actions\Orders\UpdateOrderStatus;
use App\Filament\Resources\Orders\OrderResource;
use App\Models\OrderStatus;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Validation\ValidationException;

class EditOrder extends EditRecord
{
    protected static string $resource = OrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
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
}
