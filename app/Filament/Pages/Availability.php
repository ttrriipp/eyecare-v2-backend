<?php

namespace App\Filament\Pages;

use App\Actions\Appointments\UpdateClinicHours;
use App\Actions\Appointments\UpdateProviderHours;
use App\Models\ClinicHour;
use App\Models\ProviderHour;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
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

    public array $clinicHours = [];

    public array $providerHours = [];

    public function mount(): void
    {
        $user = auth()->user();

        if ($user?->is_optometrist && $user->role->name !== 'admin') {
            $this->selectedOptometristId = $user->id;
        } else {
            $firstOptometrist = User::query()->optometrists()->first();
            $this->selectedOptometristId = $firstOptometrist?->id;
        }

        $this->loadClinicHours();
        $this->loadProviderHours();
    }

    private function loadClinicHours(): void
    {
        $hours = ClinicHour::query()->orderBy('weekday')->get()->keyBy('weekday');

        for ($day = 0; $day <= 6; $day++) {
            $hour = $hours->get($day);
            $this->clinicHours[$day] = [
                'enabled' => $hour?->enabled ?? true,
                'open_time' => $hour?->open_time ?? '09:00',
                'close_time' => $hour?->close_time ?? '17:00',
            ];
        }
    }

    private function loadProviderHours(): void
    {
        if ($this->selectedOptometristId === null) {
            return;
        }

        $hours = ProviderHour::query()
            ->where('user_id', $this->selectedOptometristId)
            ->orderBy('weekday')
            ->get()
            ->keyBy('weekday');

        for ($day = 0; $day <= 6; $day++) {
            $hour = $hours->get($day);
            $this->providerHours[$day] = [
                'enabled' => $hour?->enabled ?? false,
                'start_time' => $hour?->start_time ?? '09:00',
                'end_time' => $hour?->end_time ?? '17:00',
            ];
        }
    }

    public function updatedSelectedOptometristId(): void
    {
        $this->loadProviderHours();
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

    public function getOptometrists(): array
    {
        return User::query()
            ->optometrists()
            ->orderBy('name')
            ->pluck('name', 'id')
            ->toArray();
    }

    public function content(Schema $schema): Schema
    {
        $weekdays = $this->getWeekdays();

        return $schema->components([
            Section::make('Clinic Hours')
                ->description('Set the clinic\'s operating hours for each day of the week.')
                ->schema(array_map(fn (int $day, string $name) => Grid::make(4)->schema([
                    Toggle::make("clinicHours.{$day}.enabled")
                        ->label($name),
                    TimePicker::make("clinicHours.{$day}.open_time")
                        ->label('Open')
                        ->seconds(false)
                        ->visible(fn () => $this->clinicHours[$day]['enabled'] ?? true),
                    Placeholder::make("clinic_hours_spacer_{$day}")->label(''),
                    TimePicker::make("clinicHours.{$day}.close_time")
                        ->label('Close')
                        ->seconds(false)
                        ->visible(fn () => $this->clinicHours[$day]['enabled'] ?? true),
                ]), array_keys($weekdays), $weekdays))
                ->footer([
                    Action::make('saveClinicHours')
                        ->label('Save Clinic Hours')
                        ->action('saveClinicHours'),
                ]),

            Section::make('Optometrist Hours')
                ->description('Set individual optometrist availability. Hours must fit within clinic hours.')
                ->schema([
                    Select::make('selectedOptometristId')
                        ->label('Optometrist')
                        ->options($this->getOptometrists())
                        ->searchable()
                        ->preload()
                        ->live()
                        ->afterStateUpdated(fn () => $this->loadProviderHours()),
                    Grid::make(4)->schema(
                        collect($weekdays)->flatMap(fn (string $name, int $day) => [
                            Toggle::make("providerHours.{$day}.enabled")
                                ->label($name),
                            TimePicker::make("providerHours.{$day}.start_time")
                                ->label('Start')
                                ->seconds(false)
                                ->visible(fn () => $this->providerHours[$day]['enabled'] ?? false),
                            Placeholder::make("provider_hours_spacer_{$day}")->label(''),
                            TimePicker::make("providerHours.{$day}.end_time")
                                ->label('End')
                                ->seconds(false)
                                ->visible(fn () => $this->providerHours[$day]['enabled'] ?? false),
                        ])->toArray(),
                    ),
                ])
                ->visible(fn () => filled($this->selectedOptometristId))
                ->footer([
                    Action::make('saveProviderHours')
                        ->label('Save Provider Hours')
                        ->action('saveProviderHours'),
                ]),
        ]);
    }

    public function saveClinicHours(): void
    {
        $user = auth()->user();

        if (! $user?->isAdmin() && ! $user?->is_optometrist) {
            Notification::make()->title('Unauthorized')->danger()->send();

            return;
        }

        foreach ($this->clinicHours as $day => $hours) {
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

        foreach ($this->providerHours as $day => $hours) {
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
