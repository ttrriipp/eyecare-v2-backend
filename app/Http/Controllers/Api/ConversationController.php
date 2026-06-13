<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreConversationRequest;
use App\Http\Requests\Api\StoreMessageRequest;
use App\Http\Resources\ConversationResource;
use App\Http\Resources\MessageResource;
use App\Models\Conversation;
use App\Models\MessageAttachment;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ConversationController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();

        abort_unless($user->role->name === 'customer', 403);

        $conversations = Conversation::query()
            ->where('customer_id', $user->id)
            ->latest()
            ->get();

        return ConversationResource::collection($conversations);
    }

    public function store(StoreConversationRequest $request): JsonResponse
    {
        $conversation = Conversation::query()->create([
            'customer_id' => $request->user()->id,
            'subject' => $request->validated('subject'),
            'appointment_id' => $request->validated('appointment_id'),
            'order_id' => $request->validated('order_id'),
        ]);

        $conversation->messages()->create([
            'sender_id' => $request->user()->id,
            'body' => $request->validated('body'),
        ]);

        $conversation->load('messages');

        return response()->json([
            'data' => ConversationResource::make($conversation),
        ], 201);
    }

    public function indexMessages(Request $request, Conversation $conversation): AnonymousResourceCollection
    {
        abort_unless($this->canAccessConversation($request->user(), $conversation), 404);

        $messages = $conversation->messages()->with('attachments')->oldest()->get();

        return MessageResource::collection($messages);
    }

    public function storeMessage(StoreMessageRequest $request, Conversation $conversation): JsonResponse
    {
        abort_unless($this->canAccessConversation($request->user(), $conversation), 404);

        $message = $conversation->messages()->create([
            'sender_id' => $request->user()->id,
            'body' => $request->validated('body'),
        ]);

        if ($request->hasFile('attachment')) {
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

        return response()->json([
            'data' => MessageResource::make($message),
        ], 201);
    }

    private function canAccessConversation(User $user, Conversation $conversation): bool
    {
        if ($user->role->name === 'customer') {
            return $conversation->isParticipant($user);
        }

        return true;
    }

    public function downloadAttachment(Request $request, MessageAttachment $attachment): StreamedResponse
    {
        $conversation = $attachment->message->conversation;

        abort_unless($this->canAccessConversation($request->user(), $conversation), 404);

        abort_unless(Storage::disk('local')->exists($attachment->file_path), 404);

        return Storage::disk('local')->download(
            $attachment->file_path,
            $attachment->original_name,
            ['Content-Type' => $attachment->mime_type],
        );
    }
}
