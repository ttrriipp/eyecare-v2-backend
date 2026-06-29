<?php

namespace App\Filament\Resources\Appointments\Schemas;

use App\Models\Appointment;
use App\Models\AppointmentStatus;
use App\Models\Role;
use App\Models\User;
use App\Models\VisitReason;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rules\Exists;

class AppointmentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Grid::make(3)->schema([
                // ── Main (2/3) ──────────────────────────────────────
                Grid::make(1)->columnSpan(2)->schema([
                    Section::make('Appointment Details')
                        ->schema([
                            Select::make('customer_id')
                                ->relationship('customer', 'name')
                                ->required()
                                ->searchable()
                                ->preload()
                                ->disabledOn('edit')
                                ->dehydrated()
                                ->createOptionForm([
                                    TextInput::make('name')->required(),
                                    TextInput::make('phone')->required()->tel(),
                                    TextInput::make('email')->email()->nullable(),
                                ])
                                ->createOptionUsing(function (array $data): int {
                                    return User::create([
                                        'name' => $data['name'],
                                        'phone' => $data['phone'],
                                        'email' => $data['email'] ?? null,
                                        'password' => null,
                                        'role_id' => Role::query()->where('name', 'customer')->value('id'),
                                    ])->getKey();
                                })
                                ->columnSpanFull(),
                            Select::make('visit_reason_id')
                                ->relationship('visitReason', 'name')
                                ->required()
                                ->disabledOn('edit')
                                ->dehydrated(),
                            DateTimePicker::make('scheduled_at')
                                ->required()
                                ->native(false)
                                ->seconds(false)
                                ->minutesStep(15)
                                ->displayFormat('M d, Y h:i A')
                                ->prefixIcon('heroicon-o-calendar-days')
                                ->minDate(now())
                                ->rule(fn (string $operation): string => $operation === 'create' ? 'after:now' : '')
                                ->rule(fn (string $operation, ?Appointment $record): Exists|string|\Closure => function (string $attribute, mixed $value, \Closure $fail) use ($record): void {
                                    if (! $value) {
                                        return;
                                    }
                                    $duration = (int) request()->input('data.visit_reason_id')
                                        ? (VisitReason::query()->find(request()->input('data.visit_reason_id'))?->duration_minutes ?? 30)
                                        : 30;
                                    if (Appointment::conflictsWith(Carbon::parse($value), $duration, $record?->id)) {
                                        $fail('This time slot is not available. Please choose another time.');
                                    }
                                }),
                            ToggleButtons::make('appointment_status_id')
                                ->label('Status')
                                ->options(function (?Appointment $record): array {
                                    if (! $record) {
                                        return [];
                                    }

                                    $order = ['pending', 'confirmed', 'rescheduled', 'completed', 'cancelled'];

                                    $transitions = [
                                        'pending' => ['confirmed', 'cancelled'],
                                        'confirmed' => ['cancelled', 'completed'],
                                        'rescheduled' => ['confirmed', 'cancelled', 'completed'],
                                        'cancelled' => [],
                                        'completed' => [],
                                    ];

                                    $currentName = $record->status->name;
                                    $allowed = $transitions[$currentName] ?? [];
                                    $visible = [$currentName, ...$allowed];

                                    return AppointmentStatus::query()
                                        ->whereIn('name', $visible)
                                        ->get()
                                        ->sortBy(fn ($s) => array_search($s->name, $order))
                                        ->mapWithKeys(fn ($s) => [$s->id => ucfirst($s->name)])
                                        ->toArray();
                                })
                                ->colors(function (): array {
                                    $ids = once(fn () => AppointmentStatus::query()->pluck('id', 'name'));

                                    return array_filter([
                                        $ids['pending'] ?? null => 'gray',
                                        $ids['confirmed'] ?? null => 'info',
                                        $ids['rescheduled'] ?? null => 'warning',
                                        $ids['completed'] ?? null => 'success',
                                        $ids['cancelled'] ?? null => 'danger',
                                    ], fn ($k) => $k !== null, ARRAY_FILTER_USE_KEY);
                                })
                                ->inline()
                                ->disabledOn('create')
                                ->dehydrated()
                                ->hiddenOn('create')
                                ->columnSpanFull(),
                        ])
                        ->columns(2),

                    Section::make('Notes')
                        ->schema([
                            Textarea::make('contact_notes')
                                ->disabledOn('edit')
                                ->dehydrated()
                                ->columnSpanFull(),
                            Textarea::make('staff_notes')
                                ->columnSpanFull(),
                        ]),
                ]),

                // ── Sidebar (1/3) ────────────────────────────────────
                Grid::make(1)->columnSpan(1)->schema([
                    Section::make('Assignment')->schema([
                        Select::make('staff_id')
                            ->label('Assigned staff')
                            ->relationship(
                                'staff',
                                'name',
                                fn ($query) => $query->whereHas(
                                    'role',
                                    fn ($q) => $q->whereIn('name', ['staff', 'admin']),
                                ),
                            )
                            ->searchable()
                            ->preload()
                            ->nullable()
                            ->placeholder('Unassigned'),
                    ]),

                    Section::make('Timeline')
                        ->hiddenOn('create')
                        ->schema([
                            Placeholder::make('created_at')
                                ->label('Booked')
                                ->content(fn (?Appointment $record): string => $record?->created_at?->diffForHumans() ?? '—'),
                            Placeholder::make('updated_at')
                                ->label('Last updated')
                                ->content(fn (?Appointment $record): string => $record?->updated_at?->diffForHumans() ?? '—'),
                        ]),
                ]),
            ]),
        ]);
    }
}
