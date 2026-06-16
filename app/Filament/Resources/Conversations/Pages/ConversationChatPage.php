<?php

namespace App\Filament\Resources\Conversations\Pages;

use App\Filament\Resources\Conversations\ConversationResource;
use App\Models\Conversation;
use App\Models\Message;
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

    public function selectConversation(int $id): void
    {
        $this->selectedConversationId = $id;
        $this->replyBody = '';
    }

    public function sendReply(): void
    {
        Validator::make(
            ['replyBody' => $this->replyBody],
            ['replyBody' => 'required|string|max:5000'],
        )->validate();

        $conversation = Conversation::findOrFail($this->selectedConversationId);

        $conversation->messages()->create([
            'sender_id' => auth()->id(),
            'body' => $this->replyBody,
        ]);

        $this->replyBody = '';

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
        return Conversation::query()
            ->with('customer')
            ->withCount('messages')
            ->latest()
            ->get();
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
            ->with(['sender', 'attachments', 'contextLinks'])
            ->oldest()
            ->get();
    }

    #[Computed]
    public function selectedConversation(): ?Conversation
    {
        if ($this->selectedConversationId === null) {
            return null;
        }

        return Conversation::with('customer')->find($this->selectedConversationId);
    }
}
