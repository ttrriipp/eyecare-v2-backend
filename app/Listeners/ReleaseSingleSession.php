<?php

namespace App\Listeners;

use App\Models\User;
use App\Services\SingleSessionManager;
use Illuminate\Auth\Events\Logout;

class ReleaseSingleSession
{
    public function __construct(private readonly SingleSessionManager $singleSessionManager) {}

    public function handle(Logout $event): void
    {
        if (! $event->user instanceof User || ! request()->hasSession()) {
            return;
        }

        $this->singleSessionManager->release(
            $event->user,
            request()->session()->getId(),
        );
    }
}
