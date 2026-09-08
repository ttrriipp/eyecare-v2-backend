<?php

namespace App\Notifications;

use App\Enums\PatientNotificationActionType;
use App\Enums\PatientNotificationKind;
use App\Models\User;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class PatientDatabaseNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly PatientNotificationKind $kind,
        public readonly string $title,
        public readonly string $body,
        public readonly string $icon,
        public readonly string $status,
        public readonly ?PatientNotificationActionType $mobileActionType,
        public readonly ?int $mobileActionId,
        public readonly ?string $actionUrl,
        public readonly string $relatedType,
        public readonly ?int $relatedId,
        public readonly string $eventKey,
        public readonly ?int $patientId = null,
    ) {
        $this->afterCommit();
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        return $channel === 'database'
            && $notifiable instanceof User
            && ($this->patientId === null || $notifiable->patient()->whereKey($this->patientId)->exists())
            && ! $notifiable->notifications()->where('data->event_key', $this->eventKey)->exists();
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return array_merge(
            FilamentNotification::make()
                ->title($this->title)
                ->body($this->body)
                ->icon($this->icon)
                ->status($this->status)
                ->getDatabaseMessage(),
            [
                'kind' => $this->kind->value,
                'mobile_action' => $this->mobileAction(),
                'action_url' => $this->actionUrl,
                'related_type' => $this->relatedType,
                'related_id' => $this->relatedId,
                'event_key' => $this->eventKey,
            ],
        );
    }

    /**
     * @return array{type: string, id?: int}|null
     */
    private function mobileAction(): ?array
    {
        if ($this->mobileActionType === null) {
            return null;
        }

        return array_filter([
            'type' => $this->mobileActionType->value,
            'id' => $this->mobileActionId,
        ], fn (mixed $value): bool => $value !== null);
    }
}
