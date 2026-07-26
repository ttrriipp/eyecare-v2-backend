<x-filament-panels::page full-height class="fi-conversation-chat-page">
    <div class="flex h-full min-h-0 gap-4 overflow-hidden" data-chat-layout>

        {{-- Conversation list --}}
        <aside class="flex min-h-0 w-72 shrink-0 flex-col overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-white/10 dark:bg-gray-900">
            <div class="border-b border-gray-200 px-4 py-3 dark:border-white/10">
                <p class="text-sm font-semibold text-gray-700 dark:text-gray-200">
                    All Conversations
                    <span class="ml-1.5 rounded-full bg-gray-100 px-1.5 py-0.5 text-xs text-gray-500 dark:bg-white/10 dark:text-gray-400">
                        {{ $this->conversations->count() }}
                    </span>
                </p>
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
                                    <span class="truncate text-sm font-medium text-gray-800 dark:text-gray-100">
                                        {{ $conversation->patient?->full_name ?? 'Unknown' }}
                                    </span>
                                    <span class="shrink-0 rounded-full bg-gray-100 px-1.5 py-0.5 text-xs text-gray-500 dark:bg-white/10 dark:text-gray-400">
                                        {{ $conversation->messages_count }}
                                    </span>
                                </div>
                                <p class="mt-0.5 text-xs text-gray-400 dark:text-gray-500">
                                    {{ $conversation->patient?->contact_email ?? '—' }}
                                </p>
                                <p class="mt-0.5 text-xs text-gray-400 dark:text-gray-500">
                                    Started {{ $conversation->created_at->diffForHumans() }}
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
                <div class="flex items-center gap-3 border-b border-gray-200 px-5 py-3 dark:border-white/10">
                    <div class="flex-1 min-w-0">
                        <p class="text-sm font-semibold text-gray-800 dark:text-gray-100 truncate">
                            {{ $this->selectedConversation->patient?->full_name ?? 'Unknown' }}
                        </p>
                        <p class="text-xs text-gray-400 dark:text-gray-500">
                            {{ $this->selectedConversation->patient?->contact_email ?? '—' }}
                        </p>
                    </div>
                </div>

                {{-- Messages --}}
                <div
                    wire:poll.5s
                    wire:key="conversation-messages-{{ $this->selectedConversation->id }}"
                    x-data
                    x-init="$nextTick(() => requestAnimationFrame(() => { $el.scrollTop = $el.scrollHeight }))"
                    class="min-h-0 flex-1 space-y-4 overflow-y-auto px-5 py-4"
                    id="chat-messages"
                    data-chat-scroll-region="messages"
                    data-scroll-to-latest-on-init
                >
                    @forelse ($this->messages ?? [] as $message)
                        @php
                            $isStaff = $message->sender?->role?->name !== 'patient';
                            $hasImageAttachment = $message->attachments->contains(
                                fn (\App\Models\MessageAttachment $attachment): bool => str_starts_with($attachment->mime_type, 'image/'),
                            );
                            $isAttachmentPlaceholder = \Illuminate\Support\Str::of($message->body)
                                ->trim()
                                ->lower()
                                ->exactly('attachment');
                            $hasAppointmentContext = $message->contextLinks->contains(
                                fn (\App\Models\MessageContextLink $link): bool => $link->contextable_type === \App\Models\Appointment::class,
                            );
                            $hasJobOrderContext = $message->contextLinks->contains(
                                fn (\App\Models\MessageContextLink $link): bool => $link->contextable_type === \App\Models\JobOrder::class,
                            );
                            $isGeneratedContextSummary = ($hasAppointmentContext && preg_match('/^📅 Appointment: .+ — \d{4}-\d{2}-\d{2}$/u', trim($message->body)) === 1)
                                || ($hasJobOrderContext && preg_match('/^📦 Job Order #\S+$/u', trim($message->body)) === 1);
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
                                @unless (($hasImageAttachment && $isAttachmentPlaceholder) || $isGeneratedContextSummary)
                                    <div
                                        class="mt-1 rounded-2xl px-4 py-2.5 text-sm
                                            {{ $isStaff
                                                ? 'rounded-tr-sm bg-primary-600 text-white dark:bg-primary-500'
                                                : 'rounded-tl-sm bg-gray-100 text-gray-800 dark:bg-white/10 dark:text-gray-100'
                                            }}"
                                        data-message-body
                                    >
                                        {{ $message->body }}
                                    </div>
                                @endunless

                                {{-- Linked records --}}
                                @if ($message->contextLinks->isNotEmpty())
                                    <div class="mt-2 flex flex-col gap-2 {{ $isStaff ? 'items-end' : 'items-start' }}">
                                        @foreach ($message->contextLinks as $link)
                                            @php $context = $link->contextable; @endphp

                                            @if ($context instanceof \App\Models\Appointment && \App\Filament\Resources\Appointments\AppointmentResource::canEdit($context))
                                                <a
                                                    href="{{ \App\Filament\Resources\Appointments\AppointmentResource::getUrl('edit', ['record' => $context]) }}"
                                                    class="group flex w-full max-w-sm items-center gap-3 rounded-lg border border-gray-200 bg-white p-3 text-left shadow-xs transition hover:border-primary-400 hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 dark:border-white/10 dark:bg-white/5 dark:hover:border-primary-500 dark:hover:bg-white/10"
                                                    data-message-context-card="appointment"
                                                >
                                                    <span class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-primary-50 text-primary-600 dark:bg-primary-500/15 dark:text-primary-400">
                                                        <x-heroicon-o-calendar-days class="size-5" />
                                                    </span>
                                                    <span class="min-w-0 flex-1">
                                                        <span class="block text-xs font-medium text-gray-500 dark:text-gray-400">Appointment</span>
                                                        <span class="block truncate text-sm font-semibold text-gray-900 dark:text-white">
                                                            {{ $context->appointmentType?->name ?? $context->appointment_number }}
                                                        </span>
                                                        <span class="block truncate text-xs text-gray-500 dark:text-gray-400">
                                                            {{ $context->appointment_number }} · {{ $context->scheduled_at->format('M j, Y · g:i a') }}
                                                        </span>
                                                    </span>
                                                    <x-heroicon-o-chevron-right class="size-4 shrink-0 text-gray-400 transition group-hover:text-primary-500" />
                                                </a>
                                            @elseif ($context instanceof \App\Models\JobOrder)
                                                <a
                                                    href="/admin/job-orders/{{ $context->id }}/edit"
                                                    class="group flex w-full max-w-sm items-center gap-3 rounded-lg border border-gray-200 bg-white p-3 text-left shadow-xs transition hover:border-primary-400 hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 dark:border-white/10 dark:bg-white/5 dark:hover:border-primary-500 dark:hover:bg-white/10"
                                                    data-message-context-card="job-order"
                                                >
                                                    <span class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-emerald-50 text-emerald-600 dark:bg-emerald-500/15 dark:text-emerald-400">
                                                        <x-heroicon-o-clipboard-document-check class="size-5" />
                                                    </span>
                                                    <span class="min-w-0 flex-1">
                                                        <span class="block text-xs font-medium text-gray-500 dark:text-gray-400">Job Order</span>
                                                        <span class="block truncate text-sm font-semibold text-gray-900 dark:text-white">
                                                            {{ $context->job_order_number }}
                                                        </span>
                                                        <span class="block truncate text-xs text-gray-500 dark:text-gray-400">
                                                            {{ str($context->status->value ?? 'Unknown')->replace('_', ' ')->title() }} · ₱{{ number_format((float) $context->total_amount, 2) }}
                                                        </span>
                                                    </span>
                                                    <x-heroicon-o-chevron-right class="size-4 shrink-0 text-gray-400 transition group-hover:text-primary-500" />
                                                </a>
                                            @else
                                                <span class="inline-flex items-center gap-1 rounded-full border border-gray-200 bg-white px-2 py-0.5 text-xs text-gray-500 dark:border-white/10 dark:bg-white/5 dark:text-gray-400">
                                                    <span class="font-medium">{{ class_basename($link->contextable_type) }}</span>
                                                    <span>#{{ $link->contextable_id }}</span>
                                                </span>
                                            @endif
                                        @endforeach
                                    </div>
                                @endif

                                {{-- Attachments --}}
                                @if ($message->attachments->isNotEmpty())
                                    <div class="mt-2 space-y-2">
                                        @foreach ($message->attachments as $attachment)
                                            @if (str_starts_with($attachment->mime_type, 'image/'))
                                                <a
                                                    href="{{ route('attachments.preview', $attachment) }}"
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    class="inline-block max-w-full align-top"
                                                    data-message-image-attachment
                                                >
                                                    <img
                                                        src="{{ route('attachments.preview', $attachment) }}"
                                                        alt="{{ $attachment->original_name }}"
                                                        loading="lazy"
                                                        class="max-h-80 max-w-full rounded-lg border border-gray-200 object-contain dark:border-white/10"
                                                    >
                                                </a>
                                            @endif
                                            <div class="flex items-center gap-1.5 text-xs text-gray-500 dark:text-gray-400">
                                                <x-heroicon-o-paper-clip class="h-3.5 w-3.5 shrink-0" />
                                                <span>{{ $attachment->original_name }}</span>
                                                <span class="text-gray-400">({{ number_format($attachment->file_size / 1024, 1) }} KB)</span>
                                            </div>
                                        @endforeach
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
                    <form wire:submit="sendReply" class="flex gap-3 items-end">
                        <div class="flex-1">
                            <label for="reply-body" class="sr-only">Reply</label>
                            <textarea
                                id="reply-body"
                                wire:model="replyBody"
                                rows="3"
                                placeholder="Write a reply…"
                                class="block w-full resize-none rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 placeholder-gray-400 shadow-sm transition focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500 dark:border-white/10 dark:bg-white/5 dark:text-white dark:placeholder-gray-500"
                            ></textarea>
                            @error('replyBody')
                                <p class="mt-1 text-xs text-danger-600 dark:text-danger-400">{{ $message }}</p>
                            @enderror
                        </div>
                        <button
                            type="submit"
                            class="shrink-0 inline-flex items-center gap-1.5 rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white shadow-sm transition hover:bg-primary-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-2 disabled:opacity-50 dark:bg-primary-500 dark:hover:bg-primary-400"
                            wire:loading.attr="disabled"
                        >
                            <x-heroicon-o-paper-airplane class="h-4 w-4" wire:loading.remove wire:target="sendReply" />
                            <x-heroicon-o-arrow-path class="h-4 w-4 animate-spin" wire:loading wire:target="sendReply" />
                            Send
                        </button>
                    </form>
                </div>
            @endif
        </div>
    </div>
</x-filament-panels::page>
