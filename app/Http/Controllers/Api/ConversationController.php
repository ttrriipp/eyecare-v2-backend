<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreConversationRequest;
use App\Http\Requests\Api\StoreMessageRequest;
use App\Http\Resources\ConversationResource;
use App\Http\Resources\MessageResource;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ConversationController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();

        $conversations = $this->visibleConversationsQuery($user)->latest()->get();

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

        $messages = $conversation->messages()->oldest()->get();

        return MessageResource::collection($messages);
    }

    public function storeMessage(StoreMessageRequest $request, Conversation $conversation): JsonResponse
    {
        abort_unless($this->canAccessConversation($request->user(), $conversation), 404);

        $message = $conversation->messages()->create([
            'sender_id' => $request->user()->id,
            'body' => $request->validated('body'),
        ]);

        return response()->json([
            'data' => MessageResource::make($message),
        ], 201);
    }

    private function canAccessConversation(User $user, Conversation $conversation): bool
    {
        if ($user->role->name === 'customer') {
            return $conversation->customer_id === $user->id;
        }

        return true;
    }

    private function visibleConversationsQuery(User $user): Builder
    {
        $query = Conversation::query();

        if ($user->role->name === 'customer') {
            $query->where('customer_id', $user->id);
        }

        return $query;
    }
}
