<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreMessageRequest;
use App\Http\Resources\ConversationResource;
use App\Http\Resources\MessageResource;
use App\Models\Appointment;
use App\Models\Conversation;
use App\Models\MessageAttachment;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ConversationController extends Controller
{
    /**
     * GET /conversations — returns (or creates) the customer's single conversation.
     */
    public function show(Request $request): JsonResource
    {
        $user = $request->user();
        abort_unless($user->role->name === 'customer', 403);

        $conversation = Conversation::query()->firstOrCreate(['customer_id' => $user->id]);

        return ConversationResource::make($conversation);
    }

    public function indexMessages(Request $request, Conversation $conversation): AnonymousResourceCollection
    {
        abort_unless($this->canAccessConversation($request->user(), $conversation), 404);

        $messages = $conversation->messages()->with(['attachments', 'contextLinks'])->oldest()->get();

        return MessageResource::collection($messages);
    }

    public function storeMessage(StoreMessageRequest $request, Conversation $conversation): JsonResponse
    {
        abort_unless($this->canAccessConversation($request->user(), $conversation), 404);

        $message = $conversation->messages()->create([
            'sender_id' => $request->user()->id,
            'body' => $request->validated('body'),
        ]);

        foreach ($request->validated('contexts', []) as $context) {
            $contextable = $this->resolveContextable($context['type'], $context['id']);
            if ($contextable !== null) {
                $message->contextLinks()->create([
                    'contextable_type' => $contextable::class,
                    'contextable_id' => $contextable->id,
                ]);
            }
        }

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

        $message->load(['attachments', 'contextLinks']);

        return response()->json([
            'data' => MessageResource::make($message),
        ], 201);
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

    private function canAccessConversation(User $user, Conversation $conversation): bool
    {
        if ($user->role->name === 'customer') {
            return $conversation->customer_id === $user->id;
        }

        return true;
    }

    private function resolveContextable(string $type, int $id): Appointment|Order|Product|null
    {
        return match ($type) {
            'appointment' => Appointment::find($id),
            'order' => Order::find($id),
            'product' => Product::find($id),
            default => null,
        };
    }
}
