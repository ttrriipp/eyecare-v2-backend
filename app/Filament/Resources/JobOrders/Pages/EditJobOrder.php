<?php

namespace App\Filament\Resources\JobOrders\Pages;

use App\Actions\JobOrders\UpdateJobOrderStatus;
use App\Enums\JobOrderStatus;
use App\Filament\Resources\JobOrders\JobOrderResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;

class EditJobOrder extends EditRecord
{
    protected static string $resource = JobOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('start')
                ->label('Start')
                ->icon('heroicon-o-play')
                ->color('warning')
                ->visible(fn (): bool => $this->record->status === JobOrderStatus::Queued)
                ->requiresConfirmation()
                ->action(function (): void {
                    app(UpdateJobOrderStatus::class)->handle($this->record, 'in_progress');
                    $this->refreshFormData(['status', 'started_at']);
                }),

            Action::make('markReady')
                ->label('Mark Ready')
                ->icon('heroicon-o-check')
                ->color('info')
                ->visible(fn (): bool => $this->record->status === JobOrderStatus::InProgress)
                ->requiresConfirmation()
                ->action(function (): void {
                    app(UpdateJobOrderStatus::class)->handle($this->record, 'ready_for_dispensing');
                    $this->refreshFormData(['status', 'ready_at']);
                }),

            Action::make('cancel')
                ->label('Cancel')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn (): bool => in_array($this->record->status, [JobOrderStatus::Queued, JobOrderStatus::InProgress], true))
                ->requiresConfirmation()
                ->action(function (): void {
                    app(UpdateJobOrderStatus::class)->handle($this->record, 'cancelled');
                    $this->refreshFormData(['status', 'cancelled_at']);
                }),
        ];
    }
}
