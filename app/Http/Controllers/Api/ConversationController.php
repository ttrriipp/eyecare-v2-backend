<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreMessageRequest;
use App\Http\Resources\ConversationResource;
use App\Http\Resources\MessageResource;
use App\Models\Appointment;
use App\Models\Conversation;
use App\Models\JobOrder;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\Product;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ConversationController extends Controller
{
    /**
     * GET /conversation — returns (or creates) the patient's single conversation.
     */
    public function show(Request $request): JsonResource
    {
        $patient = $request->user()->patient;
        abort_unless($patient !== null, 403);

        $conversation = Conversation::query()->firstOrCreate(['patient_id' => $patient->id]);

        return ConversationResource::make($conversation);
    }

    /**
     * GET /conversation/messages — list messages in the patient's conversation.
     */
    public function indexMessages(Request $request): AnonymousResourceCollection
    {
        $conversation = $this->resolveConversation($request->user());
        abort_unless($conversation !== null, 404);

        $messages = $conversation->messages()->with(['attachments', 'contextLinks'])->oldest()->get();

        return MessageResource::collection($messages);
    }

    /**
     * POST /conversation/messages — send a message in the patient's conversation.
     */
    public function storeMessage(StoreMessageRequest $request): JsonResponse
    {
        $conversation = $this->resolveConversation($request->user());
        abort_unless($conversation !== null, 404);

        $message = $conversation->messages()->create([
            'sender_id' => $request->user()->id,
            'body' => $request->validated('body'),
        ]);

        foreach ($request->validated('contexts', []) as $context) {
            $contextable = $this->resolveContextable($context['type'], $context['id'], $request->user());
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

        abort_unless($this->canAccessConversation($request->user(), $conversation), 404);
        abort_unless(Storage::disk('local')->exists($attachment->file_path), 404);

        return Storage::disk('local')->download(
            $attachment->file_path,
            $attachment->original_name,
            ['Content-Type' => $attachment->mime_type],
        );
    }

    private function resolveConversation(User $user): ?Conversation
    {
        $patient = $user->patient;

        if ($patient === null) {
            return null;
        }

        return Conversation::query()->firstOrCreate(['patient_id' => $patient->id]);
    }

    private function notifyStaffOfMessage(Conversation $conversation, Message $message): void
    {
        $recipients = User::query()
            ->whereHas('roles', fn ($q) => $q->whereIn('name', ['staff', 'admin']))
            ->get();

        Notification::make()
            ->title('New Message')
            ->body("{$message->sender->full_name} sent a message.")
            ->actions([
                Action::make('view')
                    ->label('View')
                    ->url('/admin/conversations/'.$conversation->id)
                    ->markAsRead(),
            ])
            ->sendToDatabase($recipients);
    }

    private function canAccessConversation(User $user, Conversation $conversation): bool
    {
        if ($user->isPatient()) {
            return $conversation->patient_id === $user->patient?->id;
        }

        return true;
    }

    private function resolveContextable(string $type, int $id, User $user): Appointment|JobOrder|Product|null
    {
        return match ($type) {
            'appointment' => Appointment::query()
                ->when(
                    $user->isPatient(),
                    fn (Builder $query): Builder => $query->where('patient_id', $user->patient?->id),
                )
                ->find($id),
            'job_order' => JobOrder::query()
                ->when(
                    $user->isPatient(),
                    fn (Builder $query): Builder => $query->where('patient_id', $user->patient?->id),
                )
                ->find($id),
            'product' => Product::find($id),
            default => null,
        };
    }
}
