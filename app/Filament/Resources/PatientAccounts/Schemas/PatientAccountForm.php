<?php

namespace App\Filament\Resources\PatientAccounts\Schemas;

use App\Models\PatientLinkRequest;
use Carbon\Carbon;
use Filament\Forms\Components\Placeholder;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\TextSize;
use Illuminate\Support\Facades\DB;

class PatientAccountForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Grid::make(3)->schema([
                // ── Main (2/3) ──────────────────────────────────────
                Grid::make(1)->columnSpan(2)->schema([
                    Section::make('Account Details')->columns(2)->schema([
                        Placeholder::make('name')
                            ->label('Account Name')
                            ->content(fn ($record) => $record?->name ?? '—'),

                        Placeholder::make('email')
                            ->label('Email')
                            ->content(fn ($record) => $record?->email ?? '—'),

                        Placeholder::make('phone')
                            ->label('Phone')
                            ->content(fn ($record) => $record?->phone ?? '—'),

                        Placeholder::make('created_at')
                            ->label('Registered')
                            ->content(fn ($record) => $record?->created_at?->format('M j, Y g:i A') ?? '—'),
                    ]),

                    Section::make('Verified Contacts')->schema([
                        Placeholder::make('verified_contacts')
                            ->label('')
                            ->content(function ($record): string {
                                if ($record === null) {
                                    return '—';
                                }

                                $contacts = $record->contacts()->whereNotNull('verified_at')->get();

                                if ($contacts->isEmpty()) {
                                    return 'No verified contacts';
                                }

                                return $contacts->map(fn ($c) => strtoupper($c->type).': '.$c->encrypted_value.($c->is_primary ? ' (Primary)' : '')
                                )->implode("\n");
                            })
                            ->columnSpanFull(),
                    ]),

                    Section::make('Device Sessions')->schema([
                        Placeholder::make('device_sessions')
                            ->label('')
                            ->content(function ($record): string {
                                if ($record === null) {
                                    return '—';
                                }

                                $tokens = DB::table('personal_access_tokens')
                                    ->where('tokenable_type', 'App\\Models\\User')
                                    ->where('tokenable_id', $record->id)
                                    ->where(function ($q) {
                                        $q->whereNull('expires_at')
                                            ->orWhere('expires_at', '>', now());
                                    })
                                    ->orderBy('created_at', 'desc')
                                    ->get();

                                if ($tokens->isEmpty()) {
                                    return 'No active sessions';
                                }

                                return $tokens->map(function ($t) {
                                    $name = $t->name ?? 'Unknown device';
                                    $created = Carbon::parse($t->created_at)->format('M j, Y g:i A');
                                    $lastUsed = $t->last_used_at
                                        ? Carbon::parse($t->last_used_at)->diffForHumans()
                                        : 'Never';

                                    return "{$name} — Created {$created} — Last active: {$lastUsed}";
                                })->implode("\n");
                            })
                            ->columnSpanFull(),
                    ]),
                ]),

                // ── Sidebar (1/3) ────────────────────────────────────
                Grid::make(1)->columnSpan(1)->schema([
                    Section::make('Link Status')->schema([
                        Placeholder::make('link_status')
                            ->label('Status')
                            ->content(function ($record): string {
                                if ($record === null) {
                                    return '—';
                                }
                                if ($record->patient !== null) {
                                    return 'Linked';
                                }
                                if ($record->linkRequests()->where('status', 'pending')->exists()) {
                                    return 'Pending Review';
                                }

                                return 'Unlinked';
                            })
                            ->badge()
                            ->color(function ($record): string {
                                if ($record === null) {
                                    return 'gray';
                                }
                                if ($record->patient !== null) {
                                    return 'success';
                                }
                                if ($record->linkRequests()->where('status', 'pending')->exists()) {
                                    return 'warning';
                                }

                                return 'gray';
                            })
                            ->size(TextSize::Large),

                        Placeholder::make('linked_patient')
                            ->label('Linked Patient')
                            ->content(fn ($record) => $record?->patient?->full_name ?? '—'),

                        Placeholder::make('patient_number')
                            ->label('Patient Number')
                            ->content(fn ($record) => $record?->patient?->patient_number ?? '—'),
                    ]),

                    Section::make('Link Requests')->schema([
                        Placeholder::make('link_requests')
                            ->label('')
                            ->content(function ($record): string {
                                if ($record === null) {
                                    return '—';
                                }

                                $requests = PatientLinkRequest::where('user_id', $record->id)
                                    ->orderBy('created_at', 'desc')
                                    ->limit(10)
                                    ->get();

                                if ($requests->isEmpty()) {
                                    return 'No link requests';
                                }

                                return $requests->map(fn ($r) => "{$r->request_number} — {$r->status} — {$r->created_at->format('M j, Y g:i A')}"
                                )->implode("\n");
                            })
                            ->columnSpanFull(),
                    ]),
                ]),
            ]),
        ]);
    }
}
