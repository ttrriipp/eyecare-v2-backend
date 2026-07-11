<?php

namespace App\Filament\Resources\Appointments\Schemas;

use App\Models\Appointment;
use App\Models\AppointmentStatus;
use App\Models\Role;
use App\Models\User;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

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
                            TextInput::make('appointment_number')
                                ->label('Appointment #')
                                ->disabled()
                                ->dehydrated(false)
                                ->hiddenOn('create')
                                ->columnSpanFull(),
                            Select::make('customer_id')
                                ->label('Patient')
                                ->relationship('customer', 'name', fn ($query) => $query->patients())
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
                            Select::make('source')
                                ->label('Booking source')
                                ->options([
                                    'staff_created' => 'In person',
                                    'phone_call' => 'Phone call',
                                    'messenger' => 'Messenger',
                                ])
                                ->default('staff_created')
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
                                ->disabledOn('edit')
                                ->dehydrated(fn (string $operation): bool => $operation === 'create')
                                ->rule(fn (string $operation): string => $operation === 'create' ? 'after:now' : ''),
                            ToggleButtons::make('appointment_status_id')
                                ->label('Status')
                                ->options(function (?Appointment $record): array {
                                    if (! $record) {
                                        return [];
                                    }

                                    $order = ['pending', 'confirmed', 'arrived', 'completed', 'no_show', 'cancelled'];

                                    $transitions = [
                                        'pending' => ['confirmed', 'cancelled'],
                                        'confirmed' => ['arrived', 'no_show', 'cancelled'],
                                        'arrived' => ['completed', 'cancelled'],
                                        'cancelled' => [],
                                        'completed' => [],
                                        'no_show' => [],
                                    ];

                                    $currentName = $record->status->name;
                                    $allowed = $transitions[$currentName] ?? [];
                                    $visible = [$currentName, ...$allowed];

                                    return AppointmentStatus::query()
                                        ->whereIn('name', $visible)
                                        ->get()
                                        ->sortBy(fn ($s) => array_search($s->name, $order))
                                        ->mapWithKeys(fn ($s) => [$s->id => Str::headline($s->name)])
                                        ->toArray();
                                })
                                ->colors(function (): array {
                                    $ids = once(fn () => AppointmentStatus::query()->pluck('id', 'name'));

                                    return array_filter([
                                        $ids['pending'] ?? null => 'gray',
                                        $ids['confirmed'] ?? null => 'info',
                                        $ids['arrived'] ?? null => 'warning',
                                        $ids['completed'] ?? null => 'success',
                                        $ids['no_show'] ?? null => 'warning',
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
                    Section::make('Clinical Assignment')->schema([
                        Select::make('optometrist_id')
                            ->label('Optometrist')
                            ->relationship('optometrist', 'name', fn ($query) => $query->optometrists())
                            ->searchable()
                            ->preload()
                            ->nullable()
                            ->placeholder('Assign later'),
                    ]),

                    Section::make('Timeline')
                        ->hiddenOn('create')
                        ->schema([
                            Placeholder::make('created_at')
                                ->label('Booked')
                                ->content(fn (?Appointment $record): string => $record?->created_at?->diffForHumans() ?? '—'),
                            Placeholder::make('createdBy.name')
                                ->label('Booked by')
                                ->content(fn (?Appointment $record): string => $record?->createdBy?->name ?? 'System / patient'),
                            Placeholder::make('checked_in_at')
                                ->label('Checked in')
                                ->content(fn (?Appointment $record): string => $record?->checked_in_at?->diffForHumans() ?? '—'),
                            Placeholder::make('checkedInBy.name')
                                ->label('Checked in by')
                                ->content(fn (?Appointment $record): string => $record?->checkedInBy?->name ?? '—'),
                            Placeholder::make('updated_at')
                                ->label('Last updated')
                                ->content(fn (?Appointment $record): string => $record?->updated_at?->diffForHumans() ?? '—'),
                        ]),
                ]),
            ]),
        ]);
    }
}
