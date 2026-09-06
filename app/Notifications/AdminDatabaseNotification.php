<?php

namespace App\Notifications;

use App\Models\Role;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class AdminDatabaseNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $title,
        public readonly string $body,
        public readonly string $icon,
        public readonly string $status,
        public readonly string $url,
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
            && $notifiable->is_active
            && $notifiable->roles()
                ->whereIn('name', [Role::Staff, Role::Admin])
                ->exists();
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return FilamentNotification::make()
            ->title($this->title)
            ->body($this->body)
            ->icon($this->icon)
            ->status($this->status)
            ->actions([
                Action::make('view')
                    ->label('View')
                    ->url($this->url)
                    ->markAsRead(),
            ])
            ->getDatabaseMessage();
    }
}
