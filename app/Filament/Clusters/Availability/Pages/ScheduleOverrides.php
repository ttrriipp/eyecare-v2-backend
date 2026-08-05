<?php

namespace App\Filament\Clusters\Availability\Pages;

use App\Actions\Appointments\CreateScheduleOverride;
use App\Actions\Appointments\DeleteScheduleOverride;
use App\Enums\ScheduleOverrideType;
use App\Models\ScheduleOverride;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TimePicker;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

class ScheduleOverrides extends AvailabilityClusterPage
{
    protected static ?string $title = 'Schedule Overrides';

    protected static ?string $navigationLabel = 'Schedule Overrides';

    protected static ?int $navigationSort = 4;

    /** @var Collection<int, ScheduleOverride> */
    public Collection $overrides;

    public function mount(): void
    {
        $this->loadOverrides();
    }

    private function loadOverrides(): void
    {
        $this->overrides = ScheduleOverride::query()
            ->with('user')
            ->where('override_date', '>=', today()->toDateString())
            ->orderBy('override_date')
            ->get();
    }

    /**
     * @return array<string, string>
     */
    private function getAvailableOverrideTypeOptions(): array
    {
        $cases = $this->canManageClinicWideOverrides()
            ? ScheduleOverrideType::cases()
            : [ScheduleOverrideType::ProviderAbsence];

        return collect($cases)
            ->mapWithKeys(fn (ScheduleOverrideType $case): array => [$case->value => $case->getLabel()])
            ->all();
    }

    private function renderOverridesList(): HtmlString
    {
        if ($this->overrides->isEmpty()) {
            return new HtmlString(
                '<div class="rounded-lg border border-dashed border-gray-300 p-3 text-center text-sm text-gray-500 dark:border-white/10 dark:text-gray-400">No upcoming overrides.</div>'
            );
        }

        $badgeColors = [
            'danger' => 'bg-danger-50 text-danger-700 dark:bg-danger-500/15 dark:text-danger-400',
            'warning' => 'bg-warning-50 text-warning-700 dark:bg-warning-500/15 dark:text-warning-400',
            'info' => 'bg-info-50 text-info-700 dark:bg-info-500/15 dark:text-info-400',
        ];

        $rows = $this->overrides->map(function (ScheduleOverride $override) use ($badgeColors): string {
            $canDelete = $override->type === ScheduleOverrideType::ProviderAbsence
                ? $this->canManageProviderAbsence($override->user_id)
                : $this->canManageClinicWideOverrides();

            $date = e($override->override_date->format('D, M j, Y'));
            $badgeColor = $badgeColors[$override->type->getColor()] ?? $badgeColors['info'];
            $label = e($override->type->getLabel());

            $detail = match ($override->type) {
                ScheduleOverrideType::Closed => 'Clinic closed all day',
                ScheduleOverrideType::EarlyClose => 'Closes at '.e($override->start_time?->format('g:i A') ?? '—'),
                ScheduleOverrideType::ProviderAbsence => e($override->user?->full_name ?? 'Unknown optometrist')
                    .($override->start_time !== null
                        ? ' — away '.e($override->start_time->format('g:i A')).' to '.e($override->end_time?->format('g:i A') ?? '—')
                        : ' — away all day'),
            };

            $reason = filled($override->reason)
                ? '<div class="text-xs text-gray-500 dark:text-gray-400">'.e($override->reason).'</div>'
                : '';

            $deleteButton = $canDelete
                ? '<button type="button" wire:click="deleteOverride('.$override->id.')" wire:confirm="Remove this override?" class="shrink-0 text-sm font-medium text-danger-600 hover:underline dark:text-danger-400">Remove</button>'
                : '';

            return '<div class="flex items-center gap-x-3 p-3">'
                .'<div class="min-w-0 flex-1">'
                .'<div class="flex items-center gap-x-2">'
                .'<span class="text-sm font-semibold text-gray-900 dark:text-white">'.$date.'</span>'
                .'<span class="inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium '.$badgeColor.'">'.$label.'</span>'
                .'</div>'
                .'<div class="text-sm text-gray-600 dark:text-gray-300">'.$detail.'</div>'
                .$reason
                .'</div>'
                .$deleteButton
                .'</div>';
        })->implode('<div class="border-t border-gray-200 dark:border-white/10"></div>');

        return new HtmlString('<div class="rounded-lg border border-gray-200 dark:border-white/10">'.$rows.'</div>');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Schedule Overrides')
                ->description('Upcoming one-off closures, early-closing days, and optometrist absences. These take priority over the weekly hours above.')
                ->schema([
                    Placeholder::make('overrides_list')
                        ->hiddenLabel()
                        ->content(fn () => $this->renderOverridesList()),
                ]),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('addOverride')
                ->label('Add Override')
                ->icon(Heroicon::OutlinedPlus)
                ->modalDescription('Add a one-off exception to the weekly hours — a holiday closure, an early-closing day, or a single day an optometrist is away.')
                ->schema([
                    Select::make('type')
                        ->label('Type')
                        ->options(fn () => $this->getAvailableOverrideTypeOptions())
                        ->required()
                        ->native(false)
                        ->live(),

                    DatePicker::make('override_date')
                        ->label('Date')
                        ->required()
                        ->native(false)
                        ->minDate(today()),

                    Select::make('user_id')
                        ->label('Optometrist')
                        ->options(fn () => $this->getOptometrists())
                        ->searchable()
                        ->preload()
                        ->required(fn (Get $get): bool => $get('type') === ScheduleOverrideType::ProviderAbsence->value)
                        ->default(fn () => $this->canManageClinicWideOverrides() ? null : auth()->id())
                        ->disabled(fn () => ! $this->canManageClinicWideOverrides())
                        ->dehydrated()
                        ->visible(fn (Get $get): bool => $get('type') === ScheduleOverrideType::ProviderAbsence->value),

                    TimePicker::make('start_time')
                        ->label(fn (Get $get): string => $get('type') === ScheduleOverrideType::EarlyClose->value
                            ? 'Closes At'
                            : 'Absence Start (optional)')
                        ->helperText(fn (Get $get): ?string => $get('type') === ScheduleOverrideType::ProviderAbsence->value
                            ? 'Leave both times blank if they\'re away the whole day.'
                            : null)
                        ->seconds(false)
                        ->native(false)
                        ->required(fn (Get $get): bool => $get('type') === ScheduleOverrideType::EarlyClose->value)
                        ->visible(fn (Get $get): bool => in_array($get('type'), [
                            ScheduleOverrideType::EarlyClose->value,
                            ScheduleOverrideType::ProviderAbsence->value,
                        ], true)),

                    TimePicker::make('end_time')
                        ->label('Absence End (optional)')
                        ->seconds(false)
                        ->native(false)
                        ->visible(fn (Get $get): bool => $get('type') === ScheduleOverrideType::ProviderAbsence->value),

                    Textarea::make('reason')
                        ->label('Reason')
                        ->maxLength(255)
                        ->columnSpanFull(),
                ])
                ->action(function (array $data): void {
                    $this->createOverride($data);
                }),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createOverride(array $data): void
    {
        $user = auth()->user();

        if (! $user?->isAdmin() && ! $user?->is_optometrist) {
            Notification::make()->title('You don\'t have permission to manage schedule overrides')->danger()->send();

            return;
        }

        $type = ScheduleOverrideType::from($data['type']);

        $inClinicWide = in_array($type, [ScheduleOverrideType::Closed, ScheduleOverrideType::EarlyClose], true);

        if ($inClinicWide && ! $this->canManageClinicWideOverrides()) {
            Notification::make()->title('Only admins can close the clinic or set an early closing time')->danger()->send();

            return;
        }

        if ($type === ScheduleOverrideType::ProviderAbsence && ! $this->canManageProviderAbsence($data['user_id'] ?? null)) {
            Notification::make()->title('You can only manage your own absences')->danger()->send();

            return;
        }

        try {
            $override = app(CreateScheduleOverride::class)->handle(
                type: $type,
                overrideDate: $data['override_date'],
                userId: $data['user_id'] ?? null,
                startTime: $data['start_time'] ?? null,
                endTime: $data['end_time'] ?? null,
                reason: $data['reason'] ?? null,
            );
        } catch (ValidationException $e) {
            Notification::make()
                ->title('Could not add override')
                ->body($e->validator->errors()->first())
                ->danger()
                ->send();

            return;
        }

        $this->loadOverrides();

        Notification::make()
            ->title('Override added')
            ->body("{$type->getLabel()} — {$override->override_date->format('D, M j, Y')}")
            ->success()
            ->send();
    }

    public function deleteOverride(int $id): void
    {
        $override = ScheduleOverride::query()->find($id);

        if ($override === null) {
            return;
        }

        $canDelete = $override->type === ScheduleOverrideType::ProviderAbsence
            ? $this->canManageProviderAbsence($override->user_id)
            : $this->canManageClinicWideOverrides();

        if (! $canDelete) {
            $message = $override->type === ScheduleOverrideType::ProviderAbsence
                ? 'You can only remove your own absence overrides'
                : 'Only admins can remove a clinic closure or early closing time';

            Notification::make()->title($message)->danger()->send();

            return;
        }

        $label = $override->type->getLabel();
        $date = $override->override_date->format('D, M j, Y');

        app(DeleteScheduleOverride::class)->handle($override);
        $this->loadOverrides();

        Notification::make()
            ->title('Override removed')
            ->body("{$label} — {$date}")
            ->success()
            ->send();
    }
}
