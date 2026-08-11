<?php

namespace App\Filament\Resources\Conversations\Pages;

use App\Filament\Resources\Conversations\ConversationResource;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditConversation extends EditRecord
{
    protected static string $resource = ConversationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            Action::make('archiveInbox')
                ->label('Archive from Inbox')
                ->icon('heroicon-o-archive-box')
                ->color('gray')
                ->visible(fn (): bool => ! $this->getRecord()->isInboxArchived())
                ->requiresConfirmation()
                ->modalHeading('Archive from Inbox')
                ->modalDescription('This will remove the conversation from the staff inbox. It will automatically reappear when a new message arrives. The patient can still see the conversation.')
                ->modalSubmitActionLabel('Archive')
                ->action(function (): void {
                    $this->getRecord()->archiveInbox();
                    Notification::make()->title('Conversation archived from inbox')->success()->send();
                    $this->refreshFormData(['inbox_archived_at']);
                }),
            Action::make('restoreToInbox')
                ->label('Restore to Inbox')
                ->icon('heroicon-o-inbox-arrow-down')
                ->color('success')
                ->visible(fn (): bool => $this->getRecord()->isInboxArchived())
                ->action(function (): void {
                    $this->getRecord()->restoreToInbox();
                    Notification::make()->title('Conversation restored to inbox')->success()->send();
                    $this->refreshFormData(['inbox_archived_at']);
                }),
        ];
    }
}
