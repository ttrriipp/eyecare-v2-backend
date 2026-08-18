<x-filament-panels::page full-height class="fi-conversation-chat-page">
    <div class="flex h-full min-h-0 gap-4 overflow-hidden" data-chat-layout>

        {{-- Conversation list --}}
        <aside class="flex min-h-0 w-72 shrink-0 flex-col overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-white/10 dark:bg-gray-900">
            <div class="border-b border-gray-200 px-4 py-3 dark:border-white/10">
                <div class="flex items-center justify-between">
                    <p class="text-sm font-semibold text-gray-700 dark:text-gray-200">
                        {{ $showArchived ? 'Archived' : 'Inbox' }}
                        <span class="ml-1.5 rounded-full bg-gray-100 px-1.5 py-0.5 text-xs text-gray-500 dark:bg-white/10 dark:text-gray-400">
                            {{ $this->conversations->count() }}
                        </span>
                    </p>
                    <button
                        wire:click="$toggle('showArchived')"
                        class="text-xs text-gray-400 hover:text-gray-600 dark:hover:text-gray-300"
                    >
                        {{ $showArchived ? 'Show inbox' : 'Show archived' }}
                    </button>
                </div>
            </div>

            @if ($this->conversations->isEmpty())
                <div class="flex flex-1 items-center justify-center p-6 text-sm text-gray-400 dark:text-gray-500">
                    No conversations yet.
                </div>
            @else
                <ul role="list" class="min-h-0 flex-1 divide-y divide-gray-100 overflow-y-auto dark:divide-white/5" data-chat-scroll-region="conversations">
                    @foreach ($this->conversations as $conversation)
                        <li>
                            <button
                                wire:click="selectConversation({{ $conversation->id }})"
                                class="w-full text-left px-4 py-3 transition-colors hover:bg-gray-50 dark:hover:bg-white/5 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500
                                    {{ $selectedConversationId === $conversation->id ? 'bg-primary-50 dark:bg-primary-500/10' : '' }}"
                                aria-pressed="{{ $selectedConversationId === $conversation->id ? 'true' : 'false' }}"
                            >
                                <div class="flex items-center justify-between gap-2">
                                    <span class="min-w-0 flex-1 truncate text-sm font-medium text-gray-800 dark:text-gray-100">
                                        {{ $conversation->patient?->full_name ?? $conversation->account?->full_name ?? 'Unknown' }}
                                    </span>
                                    @if ($conversation->patient_id === null)
                                        <span class="shrink-0 rounded-full bg-warning-50 px-1.5 py-0.5 text-xs text-warning-600 dark:bg-warning-500/15 dark:text-warning-400">
                                            Unlinked
                                        </span>
                                    @endif
                                    @if ($conversation->inbox_archived_at)
                                        <span class="shrink-0 rounded-full bg-gray-100 px-1.5 py-0.5 text-xs text-gray-500 dark:bg-white/10 dark:text-gray-400">
                                            Archived
                                        </span>
                                    @endif
                                    @if ($conversation->unread_for_staff > 0)
                                        <span class="shrink-0 rounded-full bg-primary-50 px-1.5 py-0.5 text-xs font-medium text-primary-700 dark:bg-primary-500/15 dark:text-primary-400">
                                            {{ $conversation->unread_for_staff }}
                                        </span>
                                    @endif
                                </div>
                                <p class="mt-0.5 text-xs text-gray-400 dark:text-gray-500">
                                    @if ($conversation->last_message_at)
                                        {{ \Illuminate\Support\Str::limit($conversation->last_message_body ?? '', 40) }}
                                        · {{ \Carbon\Carbon::parse($conversation->last_message_at)->diffForHumans() }}
                                    @else
                                        No messages yet
                                    @endif
                                </p>
                            </button>
                        </li>
                    @endforeach
                </ul>
            @endif
        </aside>

        {{-- Chat panel --}}
        <div class="flex min-h-0 flex-1 flex-col overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-white/10 dark:bg-gray-900">

            @if ($this->selectedConversation === null)
                {{-- Empty state --}}
                <div class="flex flex-1 flex-col items-center justify-center gap-3 text-gray-400 dark:text-gray-500">
                    <x-heroicon-o-chat-bubble-left-right class="h-10 w-10 opacity-40" />
                    <p class="text-sm">Select a conversation to view the chat</p>
                </div>
            @else
                {{-- Header --}}
                <div class="flex items-center gap-2 border-b border-gray-200 px-5 py-3 dark:border-white/10">
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-semibold text-gray-800 dark:text-gray-100 truncate">
                            {{ $this->selectedConversation->patient?->full_name ?? $this->selectedConversation->account?->full_name ?? 'Unknown' }}
                        </p>
                        @if ($this->selectedConversation->patient_id !== null)
                            <a
                                href="{{ \App\Filament\Resources\Patients\PatientResource::getUrl('edit', ['record' => $this->selectedConversation->patient_id]) }}"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="inline-flex items-center gap-1 text-xs font-medium text-primary-600 hover:text-primary-700 hover:underline dark:text-primary-400 dark:hover:text-primary-300"
                            >
                                <x-heroicon-o-identification class="h-3.5 w-3.5" />
                                View patient record
                            </a>
                        @endif
                    </div>
                    @if ($this->selectedConversation->patient_id === null)
                        <div class="rounded-lg bg-warning-50 px-3 py-1.5 text-xs font-medium text-warning-700 dark:bg-warning-500/15 dark:text-warning-400">
                            Unlinked account — general inquiry only
                        </div>
                    @endif
                    <button
                        wire:click="toggleSearch"
                        class="inline-flex items-center gap-1 rounded-lg border px-2.5 py-1.5 text-xs font-medium shadow-sm transition
                            {{ $showSearch
                                ? 'border-primary-300 bg-primary-50 text-primary-700 dark:border-primary-500/30 dark:bg-primary-500/10 dark:text-primary-400'
                                : 'border-gray-200 bg-white text-gray-600 hover:bg-gray-50 dark:border-white/10 dark:bg-white/5 dark:text-gray-300 dark:hover:bg-white/10'
                            }}"
                        title="Search messages"
                        aria-label="Search messages"
                    >
                        <x-heroicon-o-magnifying-glass class="h-3.5 w-3.5" />
                    </button>
                    @if ($this->selectedConversation->isInboxArchived())
                        <button
                            wire:click="restoreConversation({{ $this->selectedConversation->id }})"
                            class="inline-flex items-center gap-1 rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-xs font-medium text-gray-600 shadow-sm transition hover:bg-gray-50 dark:border-white/10 dark:bg-white/5 dark:text-gray-300 dark:hover:bg-white/10"
                        >
                            <x-heroicon-o-arrow-uturn-left class="h-3.5 w-3.5" />
                            Restore
                        </button>
                    @else
                        {{ $this->archiveAction }}
                    @endif
                </div>

                {{-- Search panel --}}
                @if ($showSearch)
                    <div class="border-b border-gray-200 bg-gray-50 px-5 py-3 dark:border-white/10 dark:bg-white/[0.02]" wire:key="search-panel">
                        <div class="flex gap-2">
                            <div class="relative flex-1">
                                <x-heroicon-o-magnifying-glass class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" />
                                <input
                                    type="text"
                                    wire:model.live.debounce.300ms="searchQuery"
                                    placeholder="Search messages…"
                                    class="block w-full rounded-lg border border-gray-300 bg-white py-2 pl-9 pr-3 text-sm text-gray-900 placeholder-gray-400 shadow-sm transition focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500 dark:border-white/10 dark:bg-white/5 dark:text-white dark:placeholder-gray-500"
                                    autofocus
                                >
                            </div>
                        </div>
                        @if (filled($searchQuery) && $this->searchResults->isNotEmpty())
                            <div class="mt-2 max-h-60 space-y-1 overflow-y-auto">
                                @foreach ($this->searchResults as $result)
                                    <div class="flex items-start gap-2 rounded-lg px-3 py-2 text-sm transition hover:bg-white dark:hover:bg-white/5">
                                        <div class="min-w-0 flex-1">
                                            <div class="flex items-center gap-2">
                                                <span class="font-medium text-gray-800 dark:text-gray-100">
                                                    {{ $result->sender?->name ?? 'Unknown' }}
                                                </span>
                                                <span class="text-xs text-gray-400">
                                                    {{ $result->created_at->format('M j, g:i a') }}
                                                </span>
                                            </div>
                                            <p class="mt-0.5 text-gray-600 dark:text-gray-300 line-clamp-2">
                                                {{ \Illuminate\Support\Str::limit($result->body, 150) }}
                                            </p>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @elseif (filled($searchQuery))
                            <p class="mt-2 text-center text-xs text-gray-400 dark:text-gray-500">
                                No messages found.
                            </p>
                        @endif
                    </div>
                @endif

                {{-- Messages --}}
                <div
                    wire:poll.5s
                    wire:key="conversation-messages-{{ $this->selectedConversation->id }}"
                    x-data
                    x-init="$nextTick(() => requestAnimationFrame(() => {
                        $el.scrollTop = $el.scrollHeight;
                        $el.querySelectorAll('img').forEach((img) => {
                            if (! img.complete) {
                                img.addEventListener('load', () => { $el.scrollTop = $el.scrollHeight }, { once: true });
                            }
                        });
                    }))"
                    class="relative min-h-0 flex-1 space-y-4 overflow-y-auto px-5 py-4"
                    id="chat-messages"
                    data-chat-scroll-region="messages"
                    data-last-message-id="{{ optional($this->messages)->last()?->id }}"
                    data-scroll-to-latest-on-init
                >
                    <div class="pointer-events-none absolute inset-x-0 bottom-3 z-10 flex justify-center">
                        <button
                            type="button"
                            data-new-messages-pill
                            data-visible="false"
                            class="pointer-events-none inline-flex translate-y-1 scale-95 items-center gap-1.5 rounded-full bg-primary-600 px-3 py-1.5 text-xs font-medium text-white opacity-0 shadow-md transition duration-150 ease-out hover:bg-primary-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-2 data-[visible=true]:pointer-events-auto data-[visible=true]:translate-y-0 data-[visible=true]:scale-100 data-[visible=true]:opacity-100"
                        >
                            <x-heroicon-o-arrow-down class="h-3.5 w-3.5" />
                            New message
                        </button>
                    </div>
                    <div class="sr-only" role="status" aria-live="polite" data-new-message-announcer></div>

                    @forelse ($this->messages ?? [] as $message)
                        @php
                            $isStaff = $message->sender?->role?->name !== 'patient';
                            $hasAttachments = $message->attachments->isNotEmpty();
                            $isAttachmentPlaceholder = \Illuminate\Support\Str::of($message->body)
                                ->trim()
                                ->lower()
                                ->exactly('attachment');
                            $showMessageBody = ! ($hasAttachments && $isAttachmentPlaceholder);
                            $messageBubbleClasses = $isStaff
                                ? 'rounded-tr-sm bg-primary-600 text-white dark:bg-primary-500'
                                : 'rounded-tl-sm bg-gray-100 text-gray-800 dark:bg-white/10 dark:text-gray-100';
                            $attachmentCardClasses = $isStaff
                                ? 'border-white/20 bg-white/10'
                                : 'border-gray-200 bg-white dark:border-white/10 dark:bg-white/5';
                            $attachmentNameClasses = $isStaff ? 'text-white' : 'text-gray-800 dark:text-gray-100';
                            $attachmentMetaClasses = $isStaff ? 'text-white/60' : 'text-gray-500 dark:text-gray-400';
                            $attachmentIconAreaClasses = $isStaff
                                ? 'border-white/10 bg-black/10 text-white/80'
                                : 'border-gray-100 bg-gray-50 text-gray-500 dark:border-white/5 dark:bg-white/[0.03] dark:text-gray-400';
                            $attachmentActionClasses = $isStaff
                                ? 'text-white/80 hover:bg-white/15 hover:text-white'
                                : 'text-gray-500 hover:bg-gray-100 hover:text-gray-700 dark:text-gray-400 dark:hover:bg-white/10 dark:hover:text-gray-200';
                        @endphp
                        <div class="flex {{ $isStaff ? 'justify-end' : 'justify-start' }}">
                            <div class="max-w-[70%]">
                                <div class="flex items-baseline gap-2 {{ $isStaff ? 'flex-row-reverse' : '' }}">
                                    <span class="text-xs font-medium {{ $isStaff ? 'text-primary-600 dark:text-primary-400' : 'text-gray-600 dark:text-gray-400' }}">
                                        {{ $message->sender?->name ?? 'Unknown' }}
                                    </span>
                                    <span class="text-xs text-gray-400 dark:text-gray-500">
                                        {{ $message->created_at->format('M j, g:i a') }}
                                    </span>
                                </div>
                                @if ($showMessageBody || $hasAttachments)
                                    <div
                                        class="mt-1 inline-block max-w-full rounded-2xl px-4 py-2.5 text-sm {{ $messageBubbleClasses }}"
                                        data-message-bubble
                                    >
                                        @if ($showMessageBody)
                                            <div class="whitespace-pre-wrap break-words {{ $hasAttachments ? 'mb-3' : '' }}" data-message-body>
                                                {{ $message->body }}
                                            </div>
                                        @endif

                                        @if ($hasAttachments)
                                            <div
                                                @class([
                                                    'flex flex-wrap gap-2',
                                                    'border-t border-white/20 pt-3' => $showMessageBody && $isStaff,
                                                    'border-t border-gray-200 pt-3 dark:border-white/10' => $showMessageBody && ! $isStaff,
                                                ])
                                                data-message-attachments
                                            >
                                                @foreach ($message->attachments as $attachment)
                                                    @php
                                                        $isImageAttachment = str_starts_with($attachment->mime_type, 'image/');
                                                        $isPdfAttachment = $attachment->mime_type === 'application/pdf';
                                                        $isPreviewable = $isImageAttachment || $isPdfAttachment;
                                                        $primaryHref = $isPreviewable
                                                            ? route('attachments.preview', $attachment)
                                                            : route('attachments.download', $attachment);
                                                        $attachmentContainerClasses = 'overflow-hidden rounded-lg border '
                                                            .$attachmentCardClasses.' '
                                                            .($isImageAttachment ? 'inline-block max-w-full align-top' : 'w-52 max-w-full shrink-0');
                                                    @endphp
                                                    <div class="{{ $attachmentContainerClasses }}">
                                                        @if ($isImageAttachment)
                                                            <a
                                                                href="{{ route('attachments.preview', $attachment) }}"
                                                                target="_blank"
                                                                rel="noopener noreferrer"
                                                                class="block"
                                                                data-message-image-attachment
                                                            >
                                                                <img
                                                                    src="{{ route('attachments.preview', $attachment) }}"
                                                                    alt="{{ $attachment->original_name }}"
                                                                    loading="lazy"
                                                                    class="max-h-96 max-w-full object-contain"
                                                                >
                                                            </a>
                                                        @else
                                                            <a
                                                                href="{{ $primaryHref }}"
                                                                @if ($isPreviewable) target="_blank" rel="noopener noreferrer" @endif
                                                                class="flex h-16 items-center justify-center border-b {{ $attachmentIconAreaClasses }}"
                                                            >
                                                                <x-heroicon-o-document-text class="h-6 w-6" />
                                                            </a>
                                                        @endif

                                                        <div class="flex w-0 min-w-full items-center gap-1.5 px-2.5 py-2">
                                                            <div class="min-w-0 flex-1">
                                                                <p
                                                                    class="truncate text-xs font-medium {{ $attachmentNameClasses }}"
                                                                    title="{{ $attachment->original_name }}"
                                                                >
                                                                    {{ $attachment->original_name }}
                                                                </p>
                                                                <p class="text-xs {{ $attachmentMetaClasses }}">
                                                                    {{ number_format($attachment->file_size / 1024, 1) }} KB
                                                                </p>
                                                            </div>
                                                            <div class="flex shrink-0 items-center gap-0.5">
                                                                @if ($isPreviewable)
                                                                    <a
                                                                        href="{{ route('attachments.preview', $attachment) }}"
                                                                        target="_blank"
                                                                        rel="noopener noreferrer"
                                                                        title="View {{ $attachment->original_name }}"
                                                                        aria-label="View {{ $attachment->original_name }}"
                                                                        class="rounded-full p-1.5 transition {{ $attachmentActionClasses }}"
                                                                    >
                                                                        <x-heroicon-o-eye class="h-4 w-4" />
                                                                    </a>
                                                                @endif
                                                                <a
                                                                    href="{{ route('attachments.download', $attachment) }}"
                                                                    title="Download {{ $attachment->original_name }}"
                                                                    aria-label="Download {{ $attachment->original_name }}"
                                                                    class="rounded-full p-1.5 transition {{ $attachmentActionClasses }}"
                                                                >
                                                                    <x-heroicon-o-arrow-down-tray class="h-4 w-4" />
                                                                </a>
                                                            </div>
                                                        </div>
                                                    </div>
                                                @endforeach
                                            </div>
                                        @endif
                                    </div>
                                @endif
                            </div>
                        </div>
                    @empty
                        <div class="flex h-full items-center justify-center text-sm text-gray-400 dark:text-gray-500">
                            No messages yet.
                        </div>
                    @endforelse
                </div>

                {{-- Reply box --}}
                <div class="border-t border-gray-200 px-5 py-3 dark:border-white/10">
                    <form wire:submit="sendReply" class="space-y-1" x-data="{ length: {{ strlen($replyBody) }} }">
                        <div class="flex items-end gap-3">
                            <div class="flex-1">
                                <label for="reply-body" class="sr-only">Reply</label>
                                <textarea
                                    id="reply-body"
                                    wire:model="replyBody"
                                    x-on:input="length = $event.target.value.length"
                                    rows="2"
                                    maxlength="5000"
                                    placeholder="Write a reply…"
                                    aria-describedby="reply-body-counter"
                                    @error('replyBody') aria-invalid="true" aria-describedby="reply-body-error reply-body-counter" @enderror
                                    class="block w-full resize-none rounded-lg border bg-white px-3 py-2 text-sm text-gray-900 placeholder-gray-400 shadow-sm transition focus:outline-none focus:ring-1 dark:bg-white/5 dark:text-white dark:placeholder-gray-500
                                        @error('replyBody') border-danger-400 focus:border-danger-500 focus:ring-danger-500 dark:border-danger-500/50 @else border-gray-300 focus:border-primary-500 focus:ring-primary-500 dark:border-white/10 @enderror"
                                ></textarea>
                            </div>
                            <div class="flex shrink-0 items-center gap-2">
                                <label
                                    class="inline-flex cursor-pointer items-center justify-center rounded-lg border border-gray-200 bg-white p-2 text-gray-500 shadow-sm transition hover:bg-gray-50 hover:text-gray-700 dark:border-white/10 dark:bg-white/5 dark:text-gray-400 dark:hover:bg-white/10"
                                    title="Attach file"
                                >
                                    <x-heroicon-o-paper-clip class="h-4 w-4" />
                                    <input
                                        type="file"
                                        wire:model="pendingAttachment"
                                        class="hidden"
                                        accept=".pdf,.png,.jpg,.jpeg,.doc,.docx"
                                    >
                                </label>
                                <button
                                    type="submit"
                                    class="inline-flex items-center gap-1.5 rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white shadow-sm transition hover:bg-primary-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-2 disabled:opacity-50 dark:bg-primary-500 dark:hover:bg-primary-400"
                                    wire:loading.attr="disabled"
                                >
                                    <x-heroicon-o-paper-airplane class="h-4 w-4" wire:loading.remove wire:target="sendReply" />
                                    <x-heroicon-o-arrow-path class="h-4 w-4 animate-spin" wire:loading wire:target="sendReply" />
                                    Send
                                </button>
                            </div>
                        </div>
                        <div class="flex items-start justify-between gap-2">
                            <div class="flex-1">
                                @error('replyBody')
                                    <p id="reply-body-error" class="text-xs text-danger-600 dark:text-danger-400" role="alert">
                                        {{ $message }}
                                    </p>
                                @enderror
                            </div>
                            <p
                                id="reply-body-counter"
                                class="shrink-0 text-xs text-gray-400 dark:text-gray-500"
                                x-bind:class="length > 5000 ? 'text-danger-600 dark:text-danger-400' : ''"
                                x-text="length + ' / 5000'"
                            ></p>
                        </div>
                        @if ($pendingAttachment)
                            <div class="flex items-center gap-2 rounded-lg bg-gray-50 px-3 py-1.5 text-xs text-gray-600 dark:bg-white/5 dark:text-gray-400">
                                <x-heroicon-o-document-text class="h-3.5 w-3.5 shrink-0" />
                                <span class="truncate">{{ $pendingAttachment->getClientOriginalName() }}</span>
                                <button
                                    type="button"
                                    wire:click="$set('pendingAttachment', null)"
                                    class="ml-auto shrink-0 rounded p-0.5 hover:bg-gray-200 dark:hover:bg-white/10"
                                >
                                    <x-heroicon-o-x-mark class="h-3 w-3" />
                                </button>
                            </div>
                        @endif
                    </form>
                </div>
            @endif
        </div>
    </div>

    @script
    <script>
        // Livewire's wire:poll morphs #chat-messages in place without re-firing its
        // x-init, so a staff member scrolled up in history would otherwise never
        // learn a new message arrived. Only auto-scroll when already near the
        // bottom; otherwise reveal the "New message" pill.
        let atBottom = true;
        let lastContainerNode = null;
        let lastMessageId = null;

        const getContainer = () => document.querySelector('[data-chat-scroll-region="messages"]');

        const isNearBottom = (el) => el.scrollHeight - el.scrollTop - el.clientHeight < 96;

        const getPill = (el) => el.querySelector('[data-new-messages-pill]');

        const hidePill = (el) => {
            const pill = getPill(el);
            if (pill) {
                pill.dataset.visible = 'false';
            }
        };

        const showPill = (el) => {
            const pill = getPill(el);
            if (pill) {
                pill.dataset.visible = 'true';
            }
        };

        const announceNewMessage = (el) => {
            const announcer = el.querySelector('[data-new-message-announcer]');
            if (announcer) {
                announcer.textContent = 'New message received';
            }
        };

        const scrollToBottom = (el, smooth = false) => {
            el.scrollTo({ top: el.scrollHeight, behavior: smooth ? 'smooth' : 'auto' });
            hidePill(el);
        };

        // Attachment images use loading="lazy" with no reserved aspect-ratio box, so
        // el.scrollHeight at morph time doesn't yet include images still loading —
        // re-pin to bottom once each one finishes, if we were at the bottom.
        const pinLateLoadingImages = (el) => {
            el.querySelectorAll('img').forEach((img) => {
                if (img.complete) {
                    return;
                }

                img.addEventListener('load', () => {
                    if (atBottom) {
                        scrollToBottom(el);
                    }
                }, { once: true });
            });
        };

        document.addEventListener('scroll', (event) => {
            const container = getContainer();

            if (! container || event.target !== container) {
                return;
            }

            atBottom = isNearBottom(container);

            if (atBottom) {
                hidePill(container);
            }
        }, true);

        document.addEventListener('click', (event) => {
            if (! event.target.closest('[data-new-messages-pill]')) {
                return;
            }

            const container = getContainer();

            if (container) {
                scrollToBottom(container, true);
            }
        });

        $wire.interceptMessage(({ onSuccess }) => {
            onSuccess(({ onMorph }) => {
                onMorph(async () => {
                    const container = getContainer();

                    if (! container) {
                        lastContainerNode = null;
                        lastMessageId = null;

                        return;
                    }

                    const isFreshContainer = container !== lastContainerNode;
                    lastContainerNode = container;

                    const currentMessageId = container.dataset.lastMessageId || null;
                    const messageChanged = currentMessageId !== lastMessageId;
                    lastMessageId = currentMessageId;

                    if (isFreshContainer) {
                        // A different conversation was selected (or first load) —
                        // the container's own x-init handles the initial scroll.
                        atBottom = true;
                        hidePill(container);

                        return;
                    }

                    if (! messageChanged) {
                        return;
                    }

                    announceNewMessage(container);
                    pinLateLoadingImages(container);

                    if (atBottom) {
                        requestAnimationFrame(() => scrollToBottom(container));
                    } else {
                        showPill(container);
                    }
                });
            });
        });
    </script>
    @endscript
</x-filament-panels::page>
