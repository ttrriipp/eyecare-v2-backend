<?php

namespace App\Filament\Resources\Quotations\Pages;

use App\Actions\JobOrders\CreateJobOrder;
use App\Actions\Quotations\PresentQuotation;
use App\Actions\Quotations\RecordQuotationDecision;
use App\Enums\QuotationStatus;
use App\Filament\Resources\JobOrders\JobOrderResource;
use App\Filament\Resources\Quotations\QuotationResource;
use App\Models\JobOrder;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditQuotation extends EditRecord
{
    protected static string $resource = QuotationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('present')
                ->label('Present')
                ->icon('heroicon-o-paper-airplane')
                ->color('info')
                ->visible(fn (): bool => $this->record->status === QuotationStatus::Draft)
                ->requiresConfirmation()
                ->action(function (): void {
                    app(PresentQuotation::class)->handle($this->record, auth()->user());
                    $this->refreshFormData(['status']);
                }),

            Action::make('accept')
                ->label('Accept')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn (): bool => $this->record->status === QuotationStatus::Presented)
                ->requiresConfirmation()
                ->action(function (): void {
                    app(RecordQuotationDecision::class)->handle($this->record, 'accepted', auth()->user());
                    $this->refreshFormData(['status']);
                }),

            Action::make('decline')
                ->label('Decline')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn (): bool => $this->record->status === QuotationStatus::Presented)
                ->requiresConfirmation()
                ->action(function (): void {
                    app(RecordQuotationDecision::class)->handle($this->record, 'declined', auth()->user());
                    $this->refreshFormData(['status']);
                }),

            Action::make('createJobOrder')
                ->label('Create Job Order')
                ->icon('heroicon-o-wrench-screwdriver')
                ->color('success')
                ->visible(function (): bool {
                    return $this->record->status === QuotationStatus::Accepted
                        && in_array(auth()->user()?->role?->name, ['admin', 'staff'], true)
                        && ! JobOrder::query()
                            ->where('quotation_id', $this->record->id)
                            ->exists();
                })
                ->requiresConfirmation()
                ->modalHeading('Create Job Order')
                ->modalDescription('Create the clinic job order from this accepted quotation and commit its stock-managed items.')
                ->action(function (): void {
                    $creator = auth()->user();

                    abort_unless($creator instanceof User, 403);

                    $jobOrder = app(CreateJobOrder::class)->handle($this->record, $creator);

                    Notification::make()
                        ->title('Job order created')
                        ->success()
                        ->send();

                    $this->redirect(JobOrderResource::getUrl('edit', [
                        'record' => $jobOrder,
                    ]));
                }),
        ];
    }
}
