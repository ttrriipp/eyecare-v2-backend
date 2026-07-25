<?php

namespace App\Filament\Resources\Quotations\Pages;

use App\Actions\Quotations\PresentQuotation;
use App\Actions\Quotations\RecordQuotationDecision;
use App\Enums\QuotationStatus;
use App\Filament\Resources\Quotations\QuotationResource;
use Filament\Actions\Action;
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
        ];
    }
}
