<?php

namespace App\Filament\Clusters\Availability\Pages;

use App\Filament\Clusters\Availability\AvailabilityCluster;
use App\Models\User;
use Filament\Pages\Page;

abstract class AvailabilityClusterPage extends Page
{
    protected static ?string $cluster = AvailabilityCluster::class;

    protected string $view = 'filament.clusters.availability.pages.page';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user?->isAdmin() || $user?->is_optometrist;
    }

    /**
     * @return array<int, string>
     */
    public function getWeekdays(): array
    {
        return [
            0 => 'Sunday',
            1 => 'Monday',
            2 => 'Tuesday',
            3 => 'Wednesday',
            4 => 'Thursday',
            5 => 'Friday',
            6 => 'Saturday',
        ];
    }

    /**
     * @return array<int, string>
     */
    public function getOptometrists(): array
    {
        return User::query()
            ->optometrists()
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get()
            ->mapWithKeys(fn (User $user): array => [$user->id => $user->full_name])
            ->toArray();
    }

    /**
     * Only admins can create or remove a clinic-wide closure or early close;
     * a single optometrist should not be able to shut the whole clinic down.
     */
    protected function canManageClinicWideOverrides(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    /**
     * Admins can manage any provider's absence; a non-admin optometrist can only
     * manage their own, matching the rule already applied to provider hours.
     */
    protected function canManageProviderAbsence(?int $userId): bool
    {
        $user = auth()->user();

        return $user?->isAdmin() || $user?->id === $userId;
    }
}
