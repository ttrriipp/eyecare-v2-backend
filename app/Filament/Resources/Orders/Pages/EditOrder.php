<?php

namespace App\Filament\Resources\Orders\Pages;

use App\Actions\Billing\RecordPayment;
use App\Actions\Orders\UpdateOrderStatus;
use App\Filament\Resources\Orders\OrderResource;
use App\Models\OrderStatus;
use App\Models\PaymentMethod;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Validation\ValidationException;

class EditOrder extends EditRecord
{
    protected static string $resource = OrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('record_payment')
                ->label('Record Payment')
                ->icon('heroicon-o-banknotes')
                ->color('success')
                ->visible(function (): bool {
                    $billing = $this->getRecord()->billing;

                    return $billing !== null
                        && (float) $billing->balance_due > 0
                        && $billing->status->name !== 'voided';
                })
                ->schema([
                    TextInput::make('amount')
                        ->required()
                        ->numeric()
                        ->minValue(0.01)
                        ->maxValue(fn (): float => (float) ($this->getRecord()->billing?->balance_due ?? 0))
                        ->prefix('₱')
                        ->helperText(function (): ?string {
                            $billing = $this->getRecord()->billing;

                            if (! $billing || $billing->payments()->whereHas('status', fn ($q) => $q->where('name', 'posted'))->exists()) {
                                return null;
                            }

                            $half = number_format((float) $billing->total_amount / 2, 2);

                            return "Suggested downpayment (50%): ₱{$half}";
                        }),
                    Select::make('payment_method_id')
                        ->label('Method')
                        ->required()
                        ->options(fn () => PaymentMethod::query()->where('is_active', true)->pluck('name', 'id')),
                    TextInput::make('reference_number')
                        ->maxLength(100),
                    DateTimePicker::make('paid_at')
                        ->default(now()),
                ])
                ->action(function (array $data): void {
                    $billing = $this->getRecord()->billing;

                    if (! $billing) {
                        Notification::make()->title('No billing found for this order')->danger()->send();

                        return;
                    }

                    app(RecordPayment::class)->handle($billing, $data);
                    Notification::make()->title('Payment recorded')->success()->send();
                }),

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
