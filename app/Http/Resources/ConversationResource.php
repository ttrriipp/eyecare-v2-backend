<?php

namespace App\Http\Resources;

use App\Models\Conversation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Conversation
 */
class ConversationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'patient_id' => $this->patient_id,
            'unread_count' => $this->messages()
                ->where('sender_id', '!=', $request->user()->id)
                ->whereNull('read_at')
                ->count(),
            'messages' => MessageResource::collection($this->whenLoaded('messages')),
            'created_at' => $this->created_at->toISOString(),
        ];
    }
}
