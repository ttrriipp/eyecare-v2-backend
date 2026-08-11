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
        $isLinked = $request->user()->patient !== null;

        return [
            'id' => $this->id,
            'patient_id' => $this->patient_id,
            'access_level' => $isLinked ? 'linked_patient' : 'general_inquiry',
            'capabilities' => [
                'can_upload_attachments' => $isLinked,
                'can_create_context_links' => false,
            ],
            'unread_count' => $this->messages()
                ->where('sender_id', '!=', $request->user()->id)
                ->whereNull('read_at')
                ->count(),
            'created_at' => $this->created_at->toISOString(),
        ];
    }
}
