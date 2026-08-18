<?php

namespace App\Filament\Resources\Conversations\Pages;

use App\Filament\Resources\Conversations\ConversationResource;
use App\Models\Conversation;
use App\Models\Message;
use App\Notifications\NewMessageReceived;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\Computed;
use Livewire\WithFileUploads;

class ConversationChatPage extends Page
{
    use WithFileUploads;

    protected static string $resource = ConversationResource::class;

    protected string $view = 'filament.resources.conversations.pages.conversation-chat-page';

    public ?int $selectedConversationId = null;

    public string $replyBody = '';

    public bool $showArchived = false;

    public string $searchQuery = '';

    public bool $showSearch = false;

    public $pendingAttachment = null;

    protected function getActions(): array
    {
        return [];
    }

    public function selectConversation(int $id): void
    {
        if ($this->selectedConversationId !== $id) {
            $this->selectedConversationId = $id;
            $this->searchQuery = '';
            $this->showSearch = false;

            Conversation::where('id', $id)
                ->whereNotNull('account_user_id')
                ->update(['staff_last_read_at' => now()]);
        }

        $this->replyBody = '';
    }

    public function toggleSearch(): void
    {
        $this->showSearch = ! $this->showSearch;

        if (! $this->showSearch) {
            $this->searchQuery = '';
        }
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
        $hasBody = filled($this->replyBody);
        $hasAttachment = $this->pendingAttachment !== null;

        if (! $hasBody && ! $hasAttachment) {
            Notification::make()
                ->title('Type a message or attach a file')
                ->danger()
                ->send();

            return;
        }

        if ($hasBody) {
            Validator::make(
                ['replyBody' => $this->replyBody],
                ['replyBody' => 'string|max:5000'],
            )->validate();
        }

        $conversation = Conversation::findOrFail($this->selectedConversationId);

        $message = $conversation->messages()->create([
            'sender_id' => auth()->id(),
            'body' => $hasBody ? $this->replyBody : 'attachment',
        ]);

        if ($this->pendingAttachment instanceof UploadedFile) {
            $file = $this->pendingAttachment;
            $path = $file->store('attachments', 'local');

            $message->attachments()->create([
                'file_path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'file_size' => $file->getSize(),
            ]);

            $this->pendingAttachment = null;
        }

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

    /**
     * @return Collection<int, Message>
     */
    #[Computed]
    public function searchResults(): Collection
    {
        if ($this->selectedConversationId === null || blank($this->searchQuery)) {
            return collect();
        }

        return Message::query()
            ->where('conversation_id', $this->selectedConversationId)
            ->with(['sender', 'attachments'])
            ->whereFullText('body', $this->searchQuery)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(20)
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
