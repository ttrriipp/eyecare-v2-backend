<?php

namespace App\Http\Controllers\Api;

use App\Actions\Conversations\MarkConversationRead;
use App\Actions\Conversations\ResolveAccountConversation;
use App\Actions\Conversations\SearchConversationMessages;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreMessageRequest;
use App\Http\Resources\ConversationResource;
use App\Http\Resources\MessageResource;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\User;
use App\Notifications\NewMessageReceived;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ConversationController extends Controller
{
    /**
     * GET /conversation — returns (or creates) the account's single conversation.
     */
    public function show(Request $request): JsonResource
    {
        $conversation = app(ResolveAccountConversation::class)->handle($request->user());

        return ConversationResource::make($conversation);
    }

    /**
     * GET /conversation/messages — list messages in the account's conversation.
     */
    public function indexMessages(Request $request): AnonymousResourceCollection
    {
        $conversation = $this->resolveConversation($request->user());

        $paginator = $conversation->messages()
            ->with('attachments')
            ->latest()
            ->cursorPaginate(50);

        return MessageResource::collection($paginator)
            ->additional(['meta' => [
                'next_cursor' => $paginator->nextCursor()?->encode(),
                'has_more' => $paginator->hasMorePages(),
            ]]);
    }

    /**
     * POST /conversation/messages/read — mark unread messages as read.
     */
    public function markRead(Request $request): JsonResponse
    {
        $marked = app(MarkConversationRead::class)->handle($request->user());

        return response()->json(['marked_count' => $marked]);
    }

    /**
     * GET /conversation/messages/search — full-text search within the conversation.
     */
    public function searchMessages(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate(['q' => ['required', 'string', 'max:500']]);

        $paginator = app(SearchConversationMessages::class)->handle(
            $request->user(),
            $validated['q'],
        );

        return MessageResource::collection($paginator)
            ->additional(['meta' => [
                'next_cursor' => $paginator->nextCursor()?->encode(),
                'has_more' => $paginator->hasMorePages(),
            ]]);
    }

    /**
     * POST /conversation/messages — send a message in the account's conversation.
     */
    public function storeMessage(StoreMessageRequest $request): JsonResponse
    {
        $conversation = $this->resolveConversation($request->user());

        $message = $conversation->messages()->create([
            'sender_id' => $request->user()->id,
            'body' => $request->validated('body'),
        ]);

        if ($request->hasFile('attachment')) {
            abort_unless($request->user()->patient !== null, 422, 'Attachments require a linked patient account.');

            $file = $request->file('attachment');
            $path = $file->store('attachments', 'local');

            $message->attachments()->create([
                'file_path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'file_size' => $file->getSize(),
            ]);
        }

        $message->load('attachments');

        if ($request->user()->isPatient()) {
            $this->notifyStaffOfMessage($conversation, $message);
        }

        return response()->json([
            'data' => MessageResource::make($message),
        ], 201);
    }

    public function downloadAttachment(Request $request, MessageAttachment $attachment): StreamedResponse
    {
        $conversation = $attachment->message->conversation;

        // Must be linked and own the conversation
        abort_unless($request->user()->patient !== null, 404);
        abort_unless($conversation->account_user_id === $request->user()->id, 404);
        abort_unless(Storage::disk('local')->exists($attachment->file_path), 404);

        return Storage::disk('local')->download(
            $attachment->file_path,
            $attachment->original_name,
            ['Content-Type' => $attachment->mime_type],
        );
    }

    private function resolveConversation(User $user): Conversation
    {
        return app(ResolveAccountConversation::class)->handle($user);
    }

    private function notifyStaffOfMessage(Conversation $conversation, Message $message): void
    {
        $recipients = User::query()
            ->where('is_active', true)
            ->whereHas('roles', fn ($q) => $q->whereIn('name', ['staff', 'admin']))
            ->get();

        foreach ($recipients as $recipient) {
            $recipient->notify(new NewMessageReceived($message, $conversation));
        }
    }
}
