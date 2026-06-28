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
            'customer_id' => $this->customer_id,
            'unread_count' => $this->messages()
                ->where('sender_id', '!=', $this->customer_id)
                ->whereNull('read_at')
                ->count(),
            'messages' => MessageResource::collection($this->whenLoaded('messages')),
            'created_at' => $this->created_at->toISOString(),
        ];
    }
}
