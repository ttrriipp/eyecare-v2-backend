<?php

namespace App\Filament\Clusters\Availability\Pages;

use App\Actions\Appointments\UpdateClinicHours;
use App\Models\ClinicHour;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
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

class ClinicHours extends AvailabilityClusterPage
{
    protected static ?string $title = 'Clinic Hours';

    protected static ?string $navigationLabel = 'Clinic Hours';

    protected static ?int $navigationSort = 2;

    public array $clinicHours = [];

    public function mount(): void
    {
        $this->loadClinicHours();
    }

    private function loadClinicHours(): void
    {
        $hours = ClinicHour::query()->orderBy('weekday')->get()->keyBy('weekday');

        for ($day = 0; $day <= 6; $day++) {
            $hour = $hours->get($day);
            $this->clinicHours[$day] = [
                'enabled' => $hour?->enabled ?? true,
                'open_time' => $hour?->open_time?->format('H:i') ?? '09:00',
                'close_time' => $hour?->close_time?->format('H:i') ?? '17:00',
            ];
        }
    }

    public function content(Schema $schema): Schema
    {
        $weekdays = $this->getWeekdays();

        return $schema->components([
            Section::make('Clinic Hours')
                ->description('Set the clinic\'s operating hours for each day of the week.')
                ->schema([
                    Grid::make(4)->schema([
                        Placeholder::make('clinic_hours_header_day')->hiddenLabel(),
                        Placeholder::make('clinic_hours_header_open')
                            ->hiddenLabel()
                            ->content(new HtmlString('<span class="text-sm font-medium text-gray-700 dark:text-gray-200">Open</span>')),
                        Placeholder::make('clinic_hours_header_spacer')->hiddenLabel(),
                        Placeholder::make('clinic_hours_header_close')
                            ->hiddenLabel()
                            ->content(new HtmlString('<span class="text-sm font-medium text-gray-700 dark:text-gray-200">Close</span>')),
                    ]),
                    ...array_map(fn (int $day, string $name) => Grid::make(4)->schema([
                        Toggle::make("clinicHours.{$day}.enabled")
                            ->label($name)
                            ->live(),
                        TimePicker::make("clinicHours.{$day}.open_time")
                            ->label('Open')
                            ->hiddenLabel()
                            ->seconds(false)
                            ->disabled(fn (Get $get) => ! ($get("clinicHours.{$day}.enabled") ?? true)),
                        Placeholder::make("clinic_hours_spacer_{$day}")->hiddenLabel(),
                        TimePicker::make("clinicHours.{$day}.close_time")
                            ->label('Close')
                            ->hiddenLabel()
                            ->seconds(false)
                            ->disabled(fn (Get $get) => ! ($get("clinicHours.{$day}.enabled") ?? true)),
                    ]), array_keys($weekdays), $weekdays),
                ])
                ->footer([
                    Action::make('saveClinicHours')
                        ->label('Save Clinic Hours')
                        ->action('saveClinicHours'),
                ]),
        ]);
    }

    public function saveClinicHours(): void
    {
        $user = auth()->user();

        if (! $user?->isAdmin() && ! $user?->is_optometrist) {
            Notification::make()->title('You don\'t have permission to update clinic hours')->danger()->send();

            return;
        }

        $weekdays = $this->getWeekdays();

        try {
            DB::transaction(function () use ($weekdays): void {
                foreach ($this->clinicHours as $day => $hours) {
                    try {
                        app(UpdateClinicHours::class)->handle(
                            weekday: (int) $day,
                            enabled: $hours['enabled'] ?? true,
                            openTime: $hours['open_time'] ?? '09:00',
                            closeTime: $hours['close_time'] ?? '17:00',
                        );
                    } catch (ValidationException $e) {
                        throw ValidationException::withMessages([
                            'clinic_hours' => ["{$weekdays[$day]}: {$e->validator->errors()->first()}"],
                        ]);
                    }
                }
            });
        } catch (ValidationException $e) {
            Notification::make()
                ->title('Could not save clinic hours')
                ->body($e->validator->errors()->first())
                ->danger()
                ->send();

            return;
        }

        Notification::make()->title('Clinic hours saved')->success()->send();
    }
}
