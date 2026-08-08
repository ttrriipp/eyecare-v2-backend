<?php

namespace App\Filament\Clusters\Availability\Pages;

use App\Actions\Appointments\UpdateProviderHours;
use App\Models\ProviderHour;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

class OptometristHours extends AvailabilityClusterPage
{
    protected static ?string $title = 'Optometrist Hours';

    protected static ?string $navigationLabel = 'Optometrist Hours';

    protected static ?int $navigationSort = 3;

    public ?int $selectedOptometristId = null;

    public array $providerHours = [];

    public function mount(): void
    {
        $user = auth()->user();

        if ($user?->isOptometrist() && ! $user->isAdmin()) {
            $this->selectedOptometristId = $user->id;
        } else {
            $firstOptometrist = User::query()->optometrists()->first();
            $this->selectedOptometristId = $firstOptometrist?->id;
        }

        $this->loadProviderHours();
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
                'start_time' => $hour?->start_time?->format('H:i') ?? '09:00',
                'end_time' => $hour?->end_time?->format('H:i') ?? '17:00',
            ];
        }
    }

    public function updatedSelectedOptometristId(): void
    {
        $this->loadProviderHours();
    }

    public function content(Schema $schema): Schema
    {
        $weekdays = $this->getWeekdays();
        $hasOptometrists = filled($this->getOptometrists());

        return $schema->components([
            Section::make('Optometrist Hours')
                ->description('Set individual optometrist availability. Hours must fit within clinic hours.')
                ->schema([
                    Placeholder::make('no_optometrists')
                        ->hiddenLabel()
                        ->content('No optometrists have been added yet. Add an optometrist account to set their availability here.')
                        ->visible(! $hasOptometrists),

                    Select::make('selectedOptometristId')
                        ->label('Optometrist')
                        ->options($this->getOptometrists())
                        ->searchable()
                        ->preload()
                        ->live()
                        ->afterStateUpdated(fn () => $this->loadProviderHours())
                        ->visible($hasOptometrists),

                    Grid::make(4)->schema([
                        Placeholder::make('provider_hours_header_day')->hiddenLabel(),
                        Placeholder::make('provider_hours_header_start')
                            ->hiddenLabel()
                            ->content(new HtmlString('<span class="text-sm font-medium text-gray-700 dark:text-gray-200">Start</span>')),
                        Placeholder::make('provider_hours_header_spacer')->hiddenLabel(),
                        Placeholder::make('provider_hours_header_end')
                            ->hiddenLabel()
                            ->content(new HtmlString('<span class="text-sm font-medium text-gray-700 dark:text-gray-200">End</span>')),
                    ])->visible(fn (): bool => filled($this->selectedOptometristId)),

                    ...array_map(fn (int $day, string $name) => Grid::make(4)->schema([
                        Toggle::make("providerHours.{$day}.enabled")
                            ->label($name)
                            ->live(),
                        TimePicker::make("providerHours.{$day}.start_time")
                            ->label('Start')
                            ->hiddenLabel()
                            ->seconds(false)
                            ->disabled(fn (Get $get) => ! ($get("providerHours.{$day}.enabled") ?? false)),
                        Placeholder::make("provider_hours_spacer_{$day}")->hiddenLabel(),
                        TimePicker::make("providerHours.{$day}.end_time")
                            ->label('End')
                            ->hiddenLabel()
                            ->seconds(false)
                            ->disabled(fn (Get $get) => ! ($get("providerHours.{$day}.enabled") ?? false)),
                    ])->visible(fn (): bool => filled($this->selectedOptometristId)), array_keys($weekdays), $weekdays),
                ])
                ->footer([
                    Action::make('saveProviderHours')
                        ->label('Save Optometrist Hours')
                        ->action('saveProviderHours')
                        ->visible(fn (): bool => filled($this->selectedOptometristId)),
                ]),
        ]);
    }

    public function saveProviderHours(): void
    {
        $user = auth()->user();

        if (! $user?->isAdmin() && ! $user?->isOptometrist()) {
            Notification::make()->title('You don\'t have permission to update optometrist hours')->danger()->send();

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

        $weekdays = $this->getWeekdays();
        $optometristId = $this->selectedOptometristId;

        try {
            DB::transaction(function () use ($weekdays, $optometristId): void {
                foreach ($this->providerHours as $day => $hours) {
                    try {
                        app(UpdateProviderHours::class)->handle(
                            userId: $optometristId,
                            weekday: (int) $day,
                            enabled: $hours['enabled'] ?? false,
                            startTime: $hours['start_time'] ?? '09:00',
                            endTime: $hours['end_time'] ?? '17:00',
                        );
                    } catch (ValidationException $e) {
                        throw ValidationException::withMessages([
                            'provider_hours' => ["{$weekdays[$day]}: {$e->validator->errors()->first()}"],
                        ]);
                    }
                }
            });
        } catch (ValidationException $e) {
            Notification::make()
                ->title('Could not save optometrist hours')
                ->body($e->validator->errors()->first())
                ->danger()
                ->send();

            return;
        }

        Notification::make()->title('Optometrist hours saved')->success()->send();
    }
}
