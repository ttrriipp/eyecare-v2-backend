<?php

namespace App\Filament\Resources\Patients\Schemas;

use App\Models\Patient;
use App\Models\PatientInvitation;
use App\Models\User;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\TextSize;

class PatientForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Grid::make(3)->schema([
                // ── Main (2/3) ──────────────────────────────────────
                Grid::make(1)->columnSpan(2)->schema([
                    Section::make('Patient Information')->columns(2)->schema([
                        TextInput::make('first_name')
                            ->label('First Name')
                            ->required(),
                        TextInput::make('last_name')
                            ->label('Last Name')
                            ->required(),
                        TextInput::make('middle_name')
                            ->label('Middle Name')
                            ->nullable(),
                        TextInput::make('phone')
                            ->tel()
                            ->required(),
                        TextInput::make('contact_email')
                            ->label('Email')
                            ->email()
                            ->nullable(),
                        DatePicker::make('date_of_birth')
                            ->label('Date of Birth')
                            ->required()
                            ->maxDate(now()),
                        Select::make('gender')
                            ->options([
                                'male' => 'Male',
                                'female' => 'Female',
                                'other' => 'Other',
                            ])
                            ->nullable(),
                        TextInput::make('occupation')
                            ->nullable(),
                        TextInput::make('address')
                            ->nullable()
                            ->columnSpanFull(),
                    ]),
                ]),

                // ── Sidebar (1/3) ────────────────────────────────────
                Grid::make(1)->columnSpan(1)->schema([
                    Section::make('App Access')->schema([
                        Placeholder::make('app_access_status')
                            ->label('Status')
                            ->content(function ($record): string {
                                if ($record === null) {
                                    return '—';
                                }

                                if ($record->user_id !== null) {
                                    return 'Linked';
                                }

                                $pendingInvitation = PatientInvitation::where('patient_id', $record->id)
                                    ->where('status', 'pending')
                                    ->first();

                                if ($pendingInvitation !== null) {
                                    return 'Invitation sent';
                                }

                                $expiredInvitation = PatientInvitation::where('patient_id', $record->id)
                                    ->where('status', 'expired')
                                    ->exists();

                                if ($expiredInvitation) {
                                    return 'Invitation expired';
                                }

                                return 'Not invited';
                            })
                            ->badge()
                            ->color(fn ($record) => match (true) {
                                $record === null => 'gray',
                                $record->user_id !== null => 'success',
                                default => 'gray',
                            })
                            ->size(TextSize::Large)
                            ->hiddenOn('create'),

                        Select::make('user_id')
                            ->label('Link Account')
                            ->options(function () {
                                $linkedUserIds = Patient::whereNotNull('user_id')
                                    ->pluck('user_id')
                                    ->toArray();

                                return User::whereHas('role', fn ($q) => $q->where('name', 'patient'))
                                    ->whereNotIn('id', $linkedUserIds)
                                    ->get()
                                    ->mapWithKeys(fn ($user) => [
                                        $user->id => ($user->first_name && $user->last_name)
                                            ? "{$user->first_name} {$user->last_name}"
                                            : ($user->name ?? "User #{$user->id}"),
                                    ])
                                    ->toArray();
                            })
                            ->searchable()
                            ->nullable()
                            ->helperText('Optional. Link to an existing unlinked patient account.')
                            ->visibleOn('create'),
                    ]),
                ]),
            ]),
        ]);
    }
}
