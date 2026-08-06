<?php

namespace App\Filament\Clusters\Availability\Pages;

use App\Actions\Appointments\CreateScheduleOverride;
use App\Actions\Appointments\DeleteScheduleOverride;
use App\Enums\ScheduleOverrideType;
use App\Models\ScheduleOverride;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TimePicker;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class ScheduleOverrides extends AvailabilityClusterPage implements HasTable
{
    use InteractsWithTable;

    protected static ?string $title = 'Schedule Overrides';

    protected static ?string $navigationLabel = 'Schedule Overrides';

    protected static ?int $navigationSort = 4;

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

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Schedule Overrides')
                ->description('Upcoming one-off closures, early-closing days, and optometrist absences. These take priority over the weekly hours above.')
                ->schema([
                    EmbeddedTable::make(),
                ]),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => ScheduleOverride::query()
                ->with('user')
                ->where('override_date', '>=', today()->toDateString()))
            ->defaultSort('override_date')
            ->columns([
                TextColumn::make('override_date')
                    ->label('Date')
                    ->date('D, M j, Y')
                    ->sortable(),

                TextColumn::make('type')
                    ->badge(),

                TextColumn::make('detail')
                    ->label('Detail')
                    ->state(fn (ScheduleOverride $record): string => match ($record->type) {
                        ScheduleOverrideType::Closed => 'Clinic closed all day',
                        ScheduleOverrideType::EarlyClose => 'Closes at '.($record->start_time?->format('g:i A') ?? '—'),
                        ScheduleOverrideType::ProviderAbsence => ($record->user?->full_name ?? 'Unknown optometrist')
                            .($record->start_time !== null
                                ? ' — away '.$record->start_time->format('g:i A').' to '.($record->end_time?->format('g:i A') ?? '—')
                                : ' — away all day'),
                    }),

                TextColumn::make('reason')
                    ->wrap()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                Action::make('delete')
                    ->label('Remove')
                    ->icon(Heroicon::OutlinedTrash)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('Remove this override?')
                    ->visible(fn (ScheduleOverride $record): bool => $record->type === ScheduleOverrideType::ProviderAbsence
                        ? $this->canManageProviderAbsence($record->user_id)
                        : $this->canManageClinicWideOverrides())
                    ->action(fn (ScheduleOverride $record) => $this->deleteOverride($record->id)),
            ])
            ->emptyStateHeading('No upcoming overrides')
            ->emptyStateDescription('Add a closure, early-close day, or optometrist absence above.');
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

        Notification::make()
            ->title('Override removed')
            ->body("{$label} — {$date}")
            ->success()
            ->send();
    }
}
