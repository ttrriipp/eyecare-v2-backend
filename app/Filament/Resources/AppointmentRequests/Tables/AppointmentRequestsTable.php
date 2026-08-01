<?php

namespace App\Filament\Resources\AppointmentRequests\Tables;

use App\Actions\Appointments\AcceptAppointmentRequest;
use App\Actions\Appointments\RejectAppointmentRequest;
use App\Enums\AppointmentRequestStatus;
use App\Filament\Resources\AppointmentRequests\AppointmentRequestResource;
use App\Models\AppointmentRequest;
use App\Models\AppointmentType;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AppointmentRequestsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('request_number')
                    ->label('Request #')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('age')
                    ->label('Age')
                    ->state(fn (AppointmentRequest $record): string => $record->created_at->diffForHumans(['short' => true]))
                    ->sortable(query: fn (Builder $query, string $direction) => $query->orderBy('created_at', $direction)),

                TextColumn::make('patient.full_name')
                    ->label('Patient')
                    ->default(fn (AppointmentRequest $record): string => $record->patient?->full_name ?? $record->user->name ?? '—')
                    ->searchable(['patient.first_name', 'patient.last_name', 'user.name']),

                TextColumn::make('link_status')
                    ->label('Link Status')
                    ->badge()
                    ->state(fn (AppointmentRequest $record): string => match (true) {
                        $record->patient_id !== null => 'Linked',
                        default => 'Needs Link',
                    })
                    ->color(fn (string $state) => match ($state) {
                        'Linked' => 'success',
                        'Needs Link' => 'warning',
                    }),

                TextColumn::make('scheduled_at')
                    ->label('Preferred Date & Time')
                    ->dateTime('M j, Y g:i A')
                    ->sortable(),

                TextColumn::make('reason_for_visit')
                    ->label('Reason')
                    ->limit(50)
                    ->wrap(),

                TextColumn::make('expires_at')
                    ->label('Expires')
                    ->dateTime('M j, g:i A')
                    ->sortable()
                    ->color(fn (AppointmentRequest $record) => $record->expires_at->isPast() ? 'danger' : null),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn (AppointmentRequestStatus $state) => match ($state) {
                        AppointmentRequestStatus::Pending => 'warning',
                        AppointmentRequestStatus::Accepted => 'success',
                        AppointmentRequestStatus::Rejected => 'danger',
                        AppointmentRequestStatus::Cancelled => 'gray',
                        AppointmentRequestStatus::Expired => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => Str::headline($state)),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Filter::make('needs_review')
                    ->label('Needs Review')
                    ->query(fn (Builder $query) => $query
                        ->where('status', 'pending')
                        ->whereNotNull('patient_id')
                        ->where('expires_at', '>', now())
                    ),

                Filter::make('needs_patient_link')
                    ->label('Needs Patient Link')
                    ->query(fn (Builder $query) => $query
                        ->where('status', 'pending')
                        ->whereNull('patient_id')
                    ),

                SelectFilter::make('status')
                    ->options(AppointmentRequestStatus::class),
            ])
            ->recordActions([
                Action::make('view')
                    ->label('Review')
                    ->url(fn (AppointmentRequest $record) => AppointmentRequestResource::getUrl('view', ['record' => $record])),

                Action::make('accept')
                    ->label('Accept')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (AppointmentRequest $record) => $record->status === AppointmentRequestStatus::Pending && $record->patient_id !== null)
                    ->schema([
                        Select::make('appointment_type_id')
                            ->label('Appointment Type')
                            ->options(AppointmentType::pluck('name', 'id'))
                            ->required(),
                    ])
                    ->action(function (AppointmentRequest $record, array $data): void {
                        try {
                            $appointment = app(AcceptAppointmentRequest::class)->handle(
                                request: $record,
                                reviewer: auth()->user(),
                                appointmentTypeId: $data['appointment_type_id'],
                            );

                            Notification::make()
                                ->title("Appointment {$appointment->appointment_number} created")
                                ->success()
                                ->send();
                        } catch (ValidationException $e) {
                            $message = collect($e->errors())->flatten()->first() ?? 'Cannot accept.';
                            Notification::make()->title('Cannot accept')->body($message)->danger()->send();
                        }
                    }),

                Action::make('reject')
                    ->label('Reject')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (AppointmentRequest $record) => $record->status === AppointmentRequestStatus::Pending)
                    ->schema([
                        Textarea::make('reason')
                            ->label('Reason')
                            ->required(),
                    ])
                    ->requiresConfirmation()
                    ->action(function (AppointmentRequest $record, array $data): void {
                        try {
                            app(RejectAppointmentRequest::class)->handle(
                                request: $record,
                                reviewer: auth()->user(),
                                reason: $data['reason'],
                            );

                            Notification::make()->title('Request rejected')->success()->send();
                        } catch (ValidationException $e) {
                            $message = collect($e->errors())->flatten()->first() ?? 'Cannot reject.';
                            Notification::make()->title('Cannot reject')->body($message)->danger()->send();
                        }
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([]),
            ]);
    }
}
