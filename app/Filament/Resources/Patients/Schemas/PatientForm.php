<?php

namespace App\Filament\Resources\Patients\Schemas;

use App\Actions\Patients\SearchPatientDuplicates;
use App\Filament\Support\PatientDuplicateMatchCard;
use App\Models\PatientInvitation;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\TextSize;
use Illuminate\Support\HtmlString;

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
                            ->required()
                            ->live(onBlur: true),
                        TextInput::make('last_name')
                            ->label('Last Name')
                            ->required()
                            ->live(onBlur: true),
                        TextInput::make('middle_name')
                            ->label('Middle Name')
                            ->nullable()
                            ->live(onBlur: true),
                        TextInput::make('phone')
                            ->tel()
                            ->required()
                            ->prefix('+63')
                            ->live(onBlur: true)
                            ->formatStateUsing(fn (?string $state): ?string => $state !== null
                                ? preg_replace('/^\+63/', '', $state)
                                : null
                            )
                            ->dehydrateStateUsing(fn (?string $state): ?string => $state !== null
                                ? '+63'.preg_replace('/[^0-9]/', '', $state)
                                : null
                            )
                            ->rule('regex:/^[0-9]{10}$/')
                            ->validationAttribute('phone number'),
                        TextInput::make('contact_email')
                            ->label('Email')
                            ->email()
                            ->nullable()
                            ->live(onBlur: true),
                        DatePicker::make('date_of_birth')
                            ->label('Date of Birth')
                            ->required()
                            ->maxDate(now())
                            ->live(onBlur: true),
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
                    Section::make('Possible Existing Records')
                        ->visibleOn('create')
                        ->schema([
                            Placeholder::make('duplicate_matches')
                                ->hiddenLabel()
                                ->content(function (Get $get): HtmlString {
                                    $phone = filled($get('phone'))
                                        ? '+63'.preg_replace('/[^0-9]/', '', $get('phone'))
                                        : null;

                                    $fullName = trim(collect([
                                        $get('first_name'),
                                        $get('middle_name'),
                                        $get('last_name'),
                                    ])->filter()->implode(' '));

                                    $matches = app(SearchPatientDuplicates::class)->handle([
                                        'contact_email' => $get('contact_email'),
                                        'phone' => $phone,
                                        'full_name' => $fullName,
                                        'date_of_birth' => $get('date_of_birth'),
                                    ]);

                                    return PatientDuplicateMatchCard::render(
                                        $matches,
                                        'No matches yet — fill in name, phone, email, or date of birth.',
                                    );
                                }),
                        ]),

                    Section::make('App Access')
                        ->hiddenOn('create')
                        ->schema([
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
                                ->size(TextSize::Large),
                        ]),
                ]),
            ]),
        ]);
    }
}
