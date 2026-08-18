<?php

namespace App\Filament\Resources\Conversations\Pages;

use App\Filament\Resources\Conversations\ConversationResource;
use App\Models\Conversation;
use App\Models\Message;
use App\Notifications\NewMessageReceived;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\Computed;

class ConversationChatPage extends Page
{
    protected static string $resource = ConversationResource::class;

    protected string $view = 'filament.resources.conversations.pages.conversation-chat-page';

    public ?int $selectedConversationId = null;

    public string $replyBody = '';

    public bool $showArchived = false;

    public function selectConversation(int $id): void
    {
        if ($this->selectedConversationId !== $id) {
            $this->selectedConversationId = $id;

            Conversation::where('id', $id)
                ->whereNotNull('account_user_id')
                ->update(['staff_last_read_at' => now()]);
        }

        $this->replyBody = '';
    }

    public function archiveAction(): Action
    {
        return Action::make('archive')
            ->label('Archive')
            ->icon('heroicon-o-archive-box')
            ->color('gray')
            ->size('sm')
            ->outlined()
            ->requiresConfirmation()
            ->modalHeading('Archive conversation')
            ->modalDescription('The conversation will be hidden from the inbox. It will automatically restore if a new message arrives.')
            ->modalSubmitActionLabel('Archive')
            ->action(function (): void {
                $conversation = Conversation::findOrFail($this->selectedConversationId);
                $conversation->archiveInbox();

                $this->selectedConversationId = null;

                Notification::make()
                    ->title('Conversation archived')
                    ->success()
                    ->send();
            });
    }

    public function restoreConversation(int $id): void
    {
        $conversation = Conversation::findOrFail($id);
        $conversation->restoreToInbox();

        Notification::make()
            ->title('Conversation restored')
            ->success()
            ->send();
    }

    public function sendReply(): void
    {
        Validator::make(
            ['replyBody' => $this->replyBody],
            ['replyBody' => 'required|string|max:5000'],
        )->validate();

        $conversation = Conversation::findOrFail($this->selectedConversationId);

        $message = $conversation->messages()->create([
            'sender_id' => auth()->id(),
            'body' => $this->replyBody,
        ]);

        $this->replyBody = '';

        // Notify the patient account if one is linked
        if ($conversation->account) {
            $conversation->account->notify(new NewMessageReceived($message, $conversation));
        }

        Notification::make()
            ->title('Reply sent')
            ->success()
            ->send();
    }

    /**
     * @return Collection<int, Conversation>
     */
    #[Computed]
    public function conversations(): Collection
    {
        $query = Conversation::query()
            ->with(['patient', 'account'])
            ->withUnreadForStaff()
            ->withLastMessage();

        if (! $this->showArchived) {
            $query->whereNull('inbox_archived_at');
        }

        return $query->get()
            ->sortByDesc(fn (Conversation $c) => $c->last_message_at ?? $c->created_at)
            ->values();
    }

    /**
     * @return Collection<int, Message>|null
     */
    #[Computed]
    public function messages(): ?Collection
    {
        if ($this->selectedConversationId === null) {
            return null;
        }

        return Message::query()
            ->where('conversation_id', $this->selectedConversationId)
            ->with([
                'sender',
                'attachments',
            ])
            ->oldest()
            ->get();
    }

    #[Computed]
    public function selectedConversation(): ?Conversation
    {
        if ($this->selectedConversationId === null) {
            return null;
        }

        return Conversation::with(['patient', 'account'])->find($this->selectedConversationId);
    }
}
