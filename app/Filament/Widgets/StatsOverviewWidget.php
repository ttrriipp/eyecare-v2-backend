<?php

namespace App\Filament\Widgets;

use App\Enums\AppointmentRequestStatus;
use App\Enums\AppointmentStatusName;
use App\Enums\BillingRecordStatus;
use App\Enums\EncounterStatus;
use App\Enums\JobOrderStatus;
use App\Enums\QuotationStatus;
use App\Filament\Resources\AppointmentRequests\AppointmentRequestResource;
use App\Filament\Resources\Appointments\AppointmentResource;
use App\Filament\Resources\BillingRecords\BillingRecordResource;
use App\Filament\Resources\Encounters\EncounterResource;
use App\Filament\Resources\OpticalOrders\OpticalOrderResource;
use App\Models\Appointment;
use App\Models\AppointmentRequest;
use App\Models\BillingRecord;
use App\Models\Encounter;
use App\Models\JobOrder;
use App\Models\ProductVariant;
use App\Models\Quotation;
use App\Models\Role;
use App\Models\User;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget as BaseStatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Number;

class StatsOverviewWidget extends BaseStatsOverviewWidget
{
    protected static bool $isLazy = false;

    protected static ?int $sort = 1;

    protected ?string $heading = 'Needs attention';

    protected ?string $pollingInterval = '30s';

    protected function getDescription(): ?string
    {
        return 'Updated at '.now()->format('g:i A').' · refreshes every 30 seconds.';
    }

    /**
     * @return array<int, Stat>
     */
    protected function getStats(): array
    {
        $context = $this->getRoleContext();
        $data = $this->computeStatsData($context['user'], $context['isOptometristOnly']);

        if ($context['isOptometristOnly']) {
            return [
                $this->appointmentsStat($data, $context['user'], true),
                $this->patientsWaitingStat($data, $context['user'], true),
                $this->activeEncountersStat($data, $context['user'], true),
            ];
        }

        if ($context['isStaff']) {
            return [
                $this->appointmentRequestsStat($data),
                $this->patientsWaitingStat($data, $context['user']),
                $this->readyForPickupStat($data),
                $this->balancesDueStat($data),
            ];
        }

        return [
            $this->appointmentRequestsStat($data),
            $this->patientsWaitingStat($data, $context['user']),
            $this->activeEncountersStat($data, $context['user']),
            $this->readyForPickupStat($data),
        ];
    }

    /**
     * @return array{user: User, isAdmin: bool, isStaff: bool, isOptometristOnly: bool}
     */
    private function getRoleContext(): array
    {
        /** @var User $user */
        $user = auth()->user();
        $roleNames = $user->loadMissing('roles')->roles->pluck('name');
        $isAdmin = $roleNames->contains(Role::Admin);
        $isStaff = $roleNames->contains(Role::Staff);

        return [
            'user' => $user,
            'isAdmin' => $isAdmin,
            'isStaff' => $isStaff,
            'isOptometristOnly' => $roleNames->contains(Role::Optometrist) && ! $isAdmin && ! $isStaff,
        ];
    }

    /**
     * @param  array<string, int>  $data
     */
    private function appointmentRequestsStat(array $data): Stat
    {
        return Stat::make('Appointment Requests', Number::format($data['appointment_requests']))
            ->description('Patient submissions to review')
            ->descriptionIcon(Heroicon::OutlinedInboxArrowDown)
            ->color('warning')
            ->url(AppointmentRequestResource::getUrl('index', [
                'tableFilters' => [
                    'status' => ['value' => AppointmentRequestStatus::Pending->value],
                ],
            ]));
    }

    /**
     * @param  array<string, int>  $data
     */
    private function patientsWaitingStat(array $data, User $user, bool $isOptometristOnly = false): Stat
    {
        return Stat::make('Patients Waiting', Number::format($data['waiting_today']))
            ->description('Checked in today')
            ->descriptionIcon(Heroicon::OutlinedClock)
            ->color('warning')
            ->url(AppointmentResource::getUrl('index', [
                'activeTab' => AppointmentStatusName::CheckedIn->value,
                'tableFilters' => $this->appointmentTableFilters($user, $isOptometristOnly),
            ]));
    }

    /**
     * @param  array<string, int>  $data
     */
    private function activeEncountersStat(array $data, User $user, bool $isOptometristOnly = false): Stat
    {
        $parameters = [
            'activeTab' => EncounterStatus::InProgress->value,
        ];

        if ($isOptometristOnly) {
            $parameters['tableFilters'] = [
                'optometrist' => ['value' => $user->id],
            ];
        }

        return Stat::make(
            $isOptometristOnly ? 'My Active Encounters' : 'Active Encounters',
            Number::format($data['active_encounters']),
        )
            ->description('Consultations in progress')
            ->descriptionIcon(Heroicon::OutlinedDocumentText)
            ->color('info')
            ->url(EncounterResource::getUrl('index', $parameters));
    }

    /**
     * @param  array<string, int>  $data
     */
    private function appointmentsStat(array $data, User $user, bool $isOptometristOnly): Stat
    {
        return Stat::make('My Appointments Today', Number::format($data['today_appointments']))
            ->description(Number::format($data['yesterday_appointments']).' yesterday')
            ->descriptionIcon(Heroicon::OutlinedCalendarDays)
            ->color('primary')
            ->url(AppointmentResource::getUrl('index', [
                'tableFilters' => $this->appointmentTableFilters($user, $isOptometristOnly),
            ]));
    }

    /**
     * @param  array<string, int>  $data
     */
    private function readyForPickupStat(array $data): Stat
    {
        return Stat::make('Optical Orders Ready', Number::format($data['ready_for_pickup']))
            ->description('Ready for patient pickup')
            ->descriptionIcon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->url(OpticalOrderResource::getUrl('index', [
                'tableFilters' => [
                    'status' => ['value' => JobOrderStatus::ReadyForDispensing->value],
                ],
            ]));
    }

    /**
     * @param  array<string, int>  $data
     */
    private function balancesDueStat(array $data): Stat
    {
        return Stat::make('Balances Due', Number::format($data['balances_due']))
            ->description('Unpaid or partially paid')
            ->descriptionIcon(Heroicon::OutlinedBanknotes)
            ->color('warning')
            ->url(BillingRecordResource::getUrl('index', [
                'activeTab' => 'outstanding',
            ]));
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function appointmentTableFilters(User $user, bool $isOptometristOnly): array
    {
        $filters = [
            'scheduled_date' => ['scheduled_on' => today()->toDateString()],
        ];

        if ($isOptometristOnly) {
            $filters['optometrist'] = ['value' => (string) $user->id];
        }

        return $filters;
    }

    /**
     * @return array{
     *     appointment_requests: int,
     *     today_appointments: int,
     *     yesterday_appointments: int,
     *     waiting_today: int,
     *     active_encounters: int,
     *     quotations_pending: int,
     *     ready_for_pickup: int,
     *     balances_due: int,
     *     low_stock: int
     * }
     */
    private function computeStatsData(User $user, bool $isOptometristOnly): array
    {
        $todayStartsAt = today()->startOfDay();
        $tomorrowStartsAt = $todayStartsAt->copy()->addDay();
        $yesterdayStartsAt = $todayStartsAt->copy()->subDay();

        $todayAppointments = Appointment::query()
            ->where('scheduled_at', '>=', $todayStartsAt)
            ->where('scheduled_at', '<', $tomorrowStartsAt)
            ->whereHas('status', fn (Builder $query): Builder => $query->whereNotIn('name', [
                AppointmentStatusName::Cancelled->value,
                AppointmentStatusName::NoShow->value,
            ]))
            ->when($isOptometristOnly, fn (Builder $query): Builder => $query->where('optometrist_id', $user->id));

        $yesterdayAppointments = Appointment::query()
            ->where('scheduled_at', '>=', $yesterdayStartsAt)
            ->where('scheduled_at', '<', $todayStartsAt)
            ->whereHas('status', fn (Builder $query): Builder => $query->whereNotIn('name', [
                AppointmentStatusName::Cancelled->value,
                AppointmentStatusName::NoShow->value,
            ]))
            ->when($isOptometristOnly, fn (Builder $query): Builder => $query->where('optometrist_id', $user->id));

        return [
            'appointment_requests' => AppointmentRequest::query()
                ->where('status', AppointmentRequestStatus::Pending)
                ->where('expires_at', '>', now())
                ->count(),
            'today_appointments' => $todayAppointments->count(),
            'yesterday_appointments' => $yesterdayAppointments->count(),
            'waiting_today' => Appointment::query()
                ->where('scheduled_at', '>=', $todayStartsAt)
                ->where('scheduled_at', '<', $tomorrowStartsAt)
                ->whereHas('status', fn (Builder $query): Builder => $query->where('name', AppointmentStatusName::CheckedIn->value))
                ->when($isOptometristOnly, fn (Builder $query): Builder => $query->where('optometrist_id', $user->id))
                ->count(),
            'active_encounters' => Encounter::query()
                ->where('status', EncounterStatus::InProgress)
                ->when($isOptometristOnly, fn (Builder $query): Builder => $query->where('optometrist_id', $user->id))
                ->count(),
            'quotations_pending' => Quotation::query()
                ->where('status', QuotationStatus::Draft)
                ->count(),
            'ready_for_pickup' => JobOrder::query()
                ->where('status', JobOrderStatus::ReadyForDispensing)
                ->count(),
            'balances_due' => BillingRecord::query()
                ->whereIn('status', [BillingRecordStatus::Unpaid, BillingRecordStatus::PartiallyPaid])
                ->count(),
            'low_stock' => ProductVariant::query()
                ->active()
                ->needsReorder()
                ->count(),
        ];
    }
}
