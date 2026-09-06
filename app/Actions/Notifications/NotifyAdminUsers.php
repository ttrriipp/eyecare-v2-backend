<?php

namespace App\Actions\Notifications;

use App\Filament\Resources\AppointmentRequests\AppointmentRequestResource;
use App\Filament\Resources\Appointments\AppointmentResource;
use App\Filament\Resources\Conversations\ConversationResource;
use App\Filament\Resources\FrameRatings\FrameRatingResource;
use App\Filament\Resources\PatientLinkRequests\PatientLinkRequestResource;
use App\Filament\Resources\VisitRatings\VisitRatingResource;
use App\Models\Appointment;
use App\Models\AppointmentRequest;
use App\Models\FrameRating;
use App\Models\Message;
use App\Models\PatientLinkRequest;
use App\Models\Role;
use App\Models\User;
use App\Models\VisitRating;
use App\Notifications\AdminDatabaseNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Throwable;

class NotifyAdminUsers
{
    public function appointmentRequestSubmitted(AppointmentRequest $request): void
    {
        $url = $request->patient_id !== null
            ? AppointmentRequestResource::getUrl('schedule', ['record' => $request], panel: 'admin')
            : AppointmentRequestResource::getUrl('view', ['record' => $request], panel: 'admin');

        $this->handle(new AdminDatabaseNotification(
            title: 'New Appointment Request',
            body: sprintf(
                '%s submitted appointment request %s.',
                $this->appointmentRequestPatientName($request),
                $request->request_number,
            ),
            icon: 'heroicon-o-inbox-arrow-down',
            status: 'info',
            url: $url,
        ));
    }

    public function appointmentRequestCancelled(AppointmentRequest $request): void
    {
        $this->handle(new AdminDatabaseNotification(
            title: 'Appointment Request Cancelled',
            body: sprintf(
                '%s cancelled appointment request %s.',
                $this->appointmentRequestPatientName($request),
                $request->request_number,
            ),
            icon: 'heroicon-o-x-circle',
            status: 'warning',
            url: AppointmentRequestResource::getUrl('view', ['record' => $request], panel: 'admin'),
        ));
    }

    public function patientLinkRequestSubmitted(PatientLinkRequest $request): void
    {
        $accountName = $request->user?->full_name ?? 'A patient';

        $this->handle(new AdminDatabaseNotification(
            title: 'New Patient Link Request',
            body: sprintf('%s requested patient account linking (%s).', $accountName, $request->request_number),
            icon: 'heroicon-o-link',
            status: 'info',
            url: PatientLinkRequestResource::getUrl('view', ['record' => $request], panel: 'admin'),
        ));
    }

    public function patientMessageReceived(Message $message): void
    {
        $this->handle(new AdminDatabaseNotification(
            title: 'New Message',
            body: sprintf('%s sent a message in their conversation.', $message->sender?->full_name ?? 'A patient'),
            icon: 'heroicon-o-chat-bubble-left-ellipsis',
            status: 'info',
            url: ConversationResource::getUrl('index', panel: 'admin'),
        ));
    }

    public function appointmentCancelled(Appointment $appointment): void
    {
        $this->handle(new AdminDatabaseNotification(
            title: 'Appointment Cancelled by Patient',
            body: sprintf(
                '%s cancelled appointment %s on %s.',
                $appointment->patient?->full_name ?? 'A patient',
                $appointment->appointment_number,
                $appointment->scheduled_at->format('M d, Y g:i A'),
            ),
            icon: 'heroicon-o-calendar-days',
            status: 'warning',
            url: AppointmentResource::getUrl('edit', ['record' => $appointment], panel: 'admin'),
        ));
    }

    public function appointmentRescheduled(Appointment $appointment, string $previousScheduledAt): void
    {
        $this->handle(new AdminDatabaseNotification(
            title: 'Appointment Rescheduled by Patient',
            body: sprintf(
                '%s rescheduled appointment %s from %s to %s.',
                $appointment->patient?->full_name ?? 'A patient',
                $appointment->appointment_number,
                $previousScheduledAt,
                $appointment->scheduled_at->format('M d, Y g:i A'),
            ),
            icon: 'heroicon-o-calendar-days',
            status: 'warning',
            url: AppointmentResource::getUrl('edit', ['record' => $appointment], panel: 'admin'),
        ));
    }

    public function lowVisitRating(VisitRating $rating): void
    {
        $this->handle(new AdminDatabaseNotification(
            title: 'Low Visit Rating',
            body: sprintf(
                '%s rated visit %s %d/5.',
                $rating->patient?->full_name ?? 'A patient',
                $rating->appointment?->appointment_number ?? '#'.$rating->appointment_id,
                $rating->rating,
            ),
            icon: 'heroicon-o-star',
            status: 'danger',
            url: VisitRatingResource::getUrl('view', ['record' => $rating], panel: 'admin'),
        ));
    }

    public function lowFrameRating(FrameRating $rating): void
    {
        $frameName = $rating->variant?->product?->name
            ?? $rating->variant?->name
            ?? 'a frame';

        $this->handle(new AdminDatabaseNotification(
            title: 'Low Frame Rating',
            body: sprintf(
                '%s rated %s %d/5.',
                $rating->patient?->full_name ?? 'A patient',
                $frameName,
                $rating->rating,
            ),
            icon: 'heroicon-o-star',
            status: 'danger',
            url: FrameRatingResource::getUrl('edit', ['record' => $rating], panel: 'admin'),
        ));
    }

    public function handle(AdminDatabaseNotification $notification): void
    {
        DB::afterCommit(function () use ($notification): void {
            try {
                $recipients = User::query()
                    ->where('is_active', true)
                    ->whereHas(
                        'roles',
                        fn (Builder $query): Builder => $query->whereIn('name', [Role::Staff, Role::Admin]),
                    )
                    ->get();

                Notification::send($recipients, $notification);
            } catch (Throwable $exception) {
                report($exception);
            }
        });
    }

    private function appointmentRequestPatientName(AppointmentRequest $request): string
    {
        return $request->patient?->full_name
            ?? $request->getSnapshotDisplayName()
            ?? $request->user?->full_name
            ?? 'A patient';
    }
}
