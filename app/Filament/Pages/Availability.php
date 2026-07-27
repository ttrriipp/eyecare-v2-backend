<?php

namespace App\Filament\Pages;

use App\Actions\Appointments\UpdateClinicHours;
use App\Actions\Appointments\UpdateProviderHours;
use App\Models\ClinicHour;
use App\Models\ProviderHour;
use App\Models\User;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class Availability extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static ?string $navigationLabel = 'Availability';

    protected static ?string $title = 'Clinic Availability';

    protected static ?int $navigationSort = 2;

    protected static string|UnitEnum|null $navigationGroup = 'Schedule';

    protected string $view = 'filament.pages.availability';

    public ?int $selectedOptometristId = null;

    public function mount(): void
    {
        $user = auth()->user();

        if ($user?->is_optometrist && $user->role->name !== 'admin') {
            $this->selectedOptometristId = $user->id;
        } else {
            $firstOptometrist = User::query()->optometrists()->first();
            $this->selectedOptometristId = $firstOptometrist?->id;
        }
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user?->isAdmin() || $user?->is_optometrist;
    }

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

    public function getClinicHours(): array
    {
        $hours = ClinicHour::query()->orderBy('weekday')->get()->keyBy('weekday');

        $result = [];
        foreach ($this->getWeekdays() as $day => $name) {
            $hour = $hours->get($day);
            $result[$day] = [
                'name' => $name,
                'enabled' => $hour?->enabled ?? true,
                'open_time' => $hour?->open_time ?? '09:00',
                'close_time' => $hour?->close_time ?? '17:00',
            ];
        }

        return $result;
    }

    public function getProviderHours(): array
    {
        if ($this->selectedOptometristId === null) {
            return [];
        }

        $hours = ProviderHour::query()
            ->where('user_id', $this->selectedOptometristId)
            ->orderBy('weekday')
            ->get()
            ->keyBy('weekday');

        $result = [];
        foreach ($this->getWeekdays() as $day => $name) {
            $hour = $hours->get($day);
            $result[$day] = [
                'name' => $name,
                'enabled' => $hour?->enabled ?? false,
                'start_time' => $hour?->start_time ?? '09:00',
                'end_time' => $hour?->end_time ?? '17:00',
            ];
        }

        return $result;
    }

    public function getOptometrists(): array
    {
        return User::query()
            ->optometrists()
            ->orderBy('name')
            ->pluck('name', 'id')
            ->toArray();
    }

    public function saveClinicHours(): void
    {
        $user = auth()->user();

        if (! $user?->isAdmin() && ! $user?->is_optometrist) {
            Notification::make()->title('Unauthorized')->danger()->send();

            return;
        }

        $data = request()->input('clinic_hours', []);

        foreach ($data as $day => $hours) {
            app(UpdateClinicHours::class)->handle(
                weekday: (int) $day,
                enabled: $hours['enabled'] ?? true,
                openTime: $hours['open_time'] ?? '09:00',
                closeTime: $hours['close_time'] ?? '17:00',
            );
        }

        Notification::make()->title('Clinic hours saved')->success()->send();
    }

    public function saveProviderHours(): void
    {
        $user = auth()->user();

        if (! $user?->isAdmin() && ! $user?->is_optometrist) {
            Notification::make()->title('Unauthorized')->danger()->send();

            return;
        }

        if ($this->selectedOptometristId === null) {
            Notification::make()->title('No optometrist selected')->danger()->send();

            return;
        }

        // Non-admin optometrists can only edit their own hours
        if (! $user->isAdmin() && $user->id !== $this->selectedOptometristId) {
            Notification::make()->title('You can only edit your own hours')->danger()->send();

            return;
        }

        $data = request()->input('provider_hours', []);

        foreach ($data as $day => $hours) {
            app(UpdateProviderHours::class)->handle(
                userId: $this->selectedOptometristId,
                weekday: (int) $day,
                enabled: $hours['enabled'] ?? false,
                startTime: $hours['start_time'] ?? '09:00',
                endTime: $hours['end_time'] ?? '17:00',
            );
        }

        Notification::make()->title('Provider hours saved')->success()->send();
    }
}
