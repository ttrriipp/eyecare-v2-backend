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
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\WithFileUploads;

class ConversationChatPage extends Page
{
    use WithFileUploads;

    protected static string $resource = ConversationResource::class;

    protected static ?string $title = 'Messages';

    protected string $view = 'filament.resources.conversations.pages.conversation-chat-page';

    public ?int $selectedConversationId = null;

    public string $replyBody = '';

    public bool $showArchived = false;

    public string $conversationFilter = '';

    public string $searchQuery = '';

    public bool $showSearch = false;

    public $pendingAttachment = null;

    public bool $messagesFailedToLoad = false;

    public ?int $lastKnownOtherUnreadTotal = null;

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

    public function jumpToMessage(int $messageId): void
    {
        $this->showSearch = false;
        $this->searchQuery = '';

        $this->dispatch('scroll-to-message', messageId: $messageId);
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

    public function markAsUnread(): void
    {
        Conversation::where('id', $this->selectedConversationId)
            ->update(['staff_last_read_at' => null]);

        $this->selectedConversationId = null;

        Notification::make()
            ->title('Marked as unread')
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
            $originalName = $file->getClientOriginalName();
            $mimeType = $file->getMimeType();
            $fileSize = $file->getSize();
            $path = $file->store('attachments', 'local');

            $message->attachments()->create([
                'file_path' => $path,
                'original_name' => $originalName,
                'mime_type' => $mimeType,
                'file_size' => $fileSize,
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

        if ($this->showArchived) {
            $query->whereNotNull('inbox_archived_at');
        } else {
            $query->whereNull('inbox_archived_at');
        }

        $conversations = $query->get()
            ->sortByDesc(fn (Conversation $c) => $c->last_message_at ?? $c->created_at)
            ->values();

        if (filled($this->conversationFilter)) {
            $needle = Str::lower($this->conversationFilter);

            $conversations = $conversations
                ->filter(function (Conversation $conversation) use ($needle): bool {
                    $name = $conversation->patient?->full_name ?? $conversation->account?->full_name ?? '';

                    return Str::contains(Str::lower($name), $needle);
                })
                ->values();
        }

        $this->checkForOtherConversationActivity();

        return $conversations;
    }

    /**
     * Compare the unread total across all inbox threads (excluding whichever
     * one is currently open) to the last known total, so staff get a nudge
     * when a *different* conversation gets a new message while they're
     * reading this one. Independent of any active search/archive filter.
     */
    protected function checkForOtherConversationActivity(): void
    {
        $otherUnreadTotal = Conversation::query()
            ->whereNull('inbox_archived_at')
            ->when(
                $this->selectedConversationId,
                fn ($query) => $query->where('id', '!=', $this->selectedConversationId),
            )
            ->withUnreadForStaff()
            ->get()
            ->sum('unread_for_staff');

        if (
            $this->lastKnownOtherUnreadTotal !== null
            && $otherUnreadTotal > $this->lastKnownOtherUnreadTotal
        ) {
            Notification::make()
                ->title('New message in another conversation')
                ->icon('heroicon-o-chat-bubble-left-right')
                ->send();
        }

        $this->lastKnownOtherUnreadTotal = $otherUnreadTotal;
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

        $this->messagesFailedToLoad = false;

        try {
            return Message::query()
                ->where('conversation_id', $this->selectedConversationId)
                ->with([
                    'sender',
                    'attachments',
                ])
                ->oldest()
                ->get();
        } catch (\Throwable $exception) {
            report($exception);

            $this->messagesFailedToLoad = true;

            return null;
        }
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
