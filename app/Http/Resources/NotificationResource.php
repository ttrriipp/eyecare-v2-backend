<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Notifications\DatabaseNotification;

/**
 * @mixin DatabaseNotification
 */
class NotificationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->data['type'] ?? null,
            'title' => $this->data['title'] ?? null,
            'body' => $this->data['body'] ?? null,
            'action_url' => $this->data['action_url'] ?? null,
            'related_type' => $this->data['related_type'] ?? null,
            'related_id' => $this->data['related_id'] ?? null,
            'read_at' => $this->read_at?->toISOString(),
            'created_at' => $this->created_at->toISOString(),
        ];
    }
}
