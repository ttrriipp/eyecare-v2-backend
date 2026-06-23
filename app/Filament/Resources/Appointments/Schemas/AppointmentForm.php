<?php

namespace App\Filament\Resources\Appointments\Schemas;

use App\Models\Appointment;
use App\Models\AppointmentStatus;
use App\Models\Role;
use App\Models\User;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Schema;

class AppointmentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
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
                    }),
                Select::make('visit_reason_id')
                    ->relationship('visitReason', 'name')
                    ->required()
                    ->disabledOn('edit')
                    ->dehydrated(),
                ToggleButtons::make('appointment_status_id')
                    ->label('Status')
                    ->options(function (?Appointment $record): array {
                        if (! $record) {
                            return [];
                        }

                        $order = ['pending', 'confirmed', 'rescheduled', 'completed', 'cancelled'];

                        $transitions = [
                            'pending' => ['confirmed', 'rescheduled', 'cancelled'],
                            'confirmed' => ['rescheduled', 'cancelled', 'completed'],
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
                    ->colors(fn (?Appointment $record): array => [
                        AppointmentStatus::query()->where('name', 'pending')->value('id') => 'gray',
                        AppointmentStatus::query()->where('name', 'confirmed')->value('id') => 'info',
                        AppointmentStatus::query()->where('name', 'rescheduled')->value('id') => 'warning',
                        AppointmentStatus::query()->where('name', 'completed')->value('id') => 'success',
                        AppointmentStatus::query()->where('name', 'cancelled')->value('id') => 'danger',
                    ])
                    ->inline()
                    ->disabledOn('create')
                    ->dehydrated()
                    ->hiddenOn('create')
                    ->columnSpanFull(),
                DateTimePicker::make('scheduled_at')
                    ->required()
                    ->rule(fn (string $operation): string => $operation === 'create' ? 'after:now' : ''),
                Textarea::make('contact_notes')
                    ->disabledOn('edit')
                    ->dehydrated()
                    ->columnSpanFull(),
                Textarea::make('staff_notes')
                    ->columnSpanFull(),
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
            ]);
    }
}
