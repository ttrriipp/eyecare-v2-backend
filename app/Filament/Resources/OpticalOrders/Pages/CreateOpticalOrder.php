<?php

namespace App\Filament\Resources\OpticalOrders\Pages;

use App\Actions\Quotations\CreateQuotation;
use App\Filament\Resources\OpticalOrders\OpticalOrderResource;
use App\Models\Patient;
use App\Models\User;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateOpticalOrder extends CreateRecord
{
    protected static string $resource = OpticalOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CancelAction::make(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Calculate subtotal from items
        $subtotal = collect($data['items'] ?? [])
            ->sum(fn (array $item) => (float) ($item['amount'] ?? 0));

        $data['subtotal'] = $subtotal;
        $data['discount_amount'] = $data['discount_amount'] ?? 0;
        $data['total'] = max($subtotal - (float) ($data['discount_amount'] ?? 0), 0);

        return $data;
    }

    protected function handleCreation(array $data): Model
    {
        $creator = auth()->user();

        abort_unless($creator instanceof User, 403);

        $patient = Patient::findOrFail($data['patient_id']);

        try {
            return app(CreateQuotation::class)->handle(
                patient: $patient,
                creator: $creator,
                data: $data,
                encounter: isset($data['encounter_id']) ? $patient->encounters()->find($data['encounter_id']) : null,
            );
        } catch (ValidationException $e) {
            Notification::make()
                ->title('Cannot create optical order')
                ->body($e->getMessage())
                ->danger()
                ->send();

            $this->halt();
        }
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Optical order created';
    }

    protected function getRedirectUrl(): string
    {
        return OpticalOrderResource::getUrl('edit', ['record' => $this->record]);
    }
}
