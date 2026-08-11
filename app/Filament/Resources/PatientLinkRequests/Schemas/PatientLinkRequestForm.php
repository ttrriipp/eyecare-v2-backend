<?php

namespace App\Filament\Resources\PatientLinkRequests\Schemas;

use App\Models\PatientLinkRequest;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

class PatientLinkRequestForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Request Details')
                    ->columns(2)
                    ->schema([
                        Placeholder::make('request_number')
                            ->label('Request #')
                            ->content(fn ($record) => $record?->request_number ?? '—'),

                        Placeholder::make('status')
                            ->label('Status')
                            ->badge()
                            ->color(fn ($record) => match ($record?->status) {
                                'pending' => 'warning',
                                'approved' => 'success',
                                'rejected' => 'danger',
                                default => 'gray',
                            })
                            ->content(fn ($record) => $record ? Str::headline($record->status) : '—'),

                        Placeholder::make('account_owner')
                            ->label('Account Owner')
                            ->content(fn ($record): string => $record?->user?->full_name ?? '—'),

                        Placeholder::make('account_email')
                            ->label('Account Email')
                            ->content(fn ($record) => $record?->user?->email ?? '—'),

                        Placeholder::make('account_phone')
                            ->label('Account Phone')
                            ->content(fn ($record) => $record?->user?->phone ?? '—'),

                        Placeholder::make('request_age')
                            ->label('Request Age')
                            ->content(fn ($record): string => $record?->created_at?->diffForHumans() ?? '—'),

                        Placeholder::make('submitted_identity')
                            ->label('Submitted Identity')
                            ->columnSpanFull()
                            ->content(function ($record): string {
                                if ($record === null) {
                                    return '—';
                                }

                                $snapshot = $record->encrypted_identity_snapshot ?? [];
                                $name = trim(($snapshot['first_name'] ?? '').' '.($snapshot['last_name'] ?? ''));
                                $dob = $snapshot['date_of_birth'] ?? null;

                                return trim($name.($dob ? " — DOB {$dob}" : '')) ?: '—';
                            }),
                    ]),

                Section::make('Candidate Matches')
                    ->schema([
                        Placeholder::make('candidates_list')
                            ->hiddenLabel()
                            ->content(function (?PatientLinkRequest $record): HtmlString {
                                if ($record === null) {
                                    return new HtmlString('—');
                                }

                                $candidates = $record->candidates()->with('patient')->orderBy('rank')->get();

                                if ($candidates->isEmpty()) {
                                    return new HtmlString('<span class="text-sm text-gray-500 dark:text-gray-400">No candidate matches found.</span>');
                                }

                                $colors = [
                                    'strong' => 'text-success-700 bg-success-50 dark:text-success-400 dark:bg-success-500/10',
                                    'moderate' => 'text-warning-700 bg-warning-50 dark:text-warning-400 dark:bg-warning-500/10',
                                    'weak' => 'text-gray-600 bg-gray-100 dark:text-gray-400 dark:bg-gray-500/10',
                                ];

                                $rows = $candidates->map(function ($candidate) use ($colors): string {
                                    $patient = $candidate->patient;

                                    if ($patient === null) {
                                        return '';
                                    }

                                    $color = $colors[$candidate->match_strength] ?? $colors['weak'];
                                    $reasons = collect($candidate->reason_codes ?? [])
                                        ->map(fn (string $reason): string => Str::headline($reason))
                                        ->implode(', ');

                                    return '<li class="mb-2">'
                                        .'<span class="inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium '.$color.'">'
                                        .e(Str::headline($candidate->match_strength)).'</span> '
                                        .'<span class="font-medium">'.e($patient->full_name).'</span> — '.e($patient->patient_number)
                                        .($reasons !== '' ? '<div class="text-xs text-gray-500 dark:text-gray-400">'.e($reasons).'</div>' : '')
                                        .'</li>';
                                })->implode('');

                                return new HtmlString('<ul class="list-none space-y-1 text-sm">'.$rows.'</ul>');
                            }),
                    ])
                    ->visible(fn ($record) => $record !== null),

                Section::make('Decision')
                    ->columns(2)
                    ->schema([
                        Placeholder::make('linked_patient')
                            ->label('Linked Patient')
                            ->content(fn ($record) => $record?->reviewedPatient?->full_name ?? '—'),

                        Placeholder::make('reviewer')
                            ->label('Reviewed By')
                            ->content(fn ($record): string => $record?->reviewer?->full_name ?? '—'),

                        Placeholder::make('reviewed_at')
                            ->label('Reviewed At')
                            ->content(fn ($record): string => $record?->reviewed_at?->format('M j, Y g:i A') ?? '—'),

                        Textarea::make('decision_note')
                            ->label('Decision Note')
                            ->disabled()
                            ->columnSpanFull(),
                    ])
                    ->visible(fn ($record) => $record !== null && $record->status !== 'pending'),
            ]);
    }
}
