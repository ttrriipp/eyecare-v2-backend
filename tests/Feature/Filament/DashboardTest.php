<?php

use App\Filament\Pages\Dashboard as AdminDashboard;
use App\Filament\Resources\AppointmentRequests\AppointmentRequestResource;
use App\Filament\Resources\Appointments\AppointmentResource;
use App\Filament\Resources\Appointments\Widgets\AppointmentCalendarWidget;
use App\Filament\Resources\Encounters\EncounterResource;
use App\Filament\Resources\OpticalOrders\OpticalOrderResource;
use App\Filament\Widgets\AppointmentsChartWidget;
use App\Filament\Widgets\EncounterStatsWidget;
use App\Filament\Widgets\OtherIssuesWidget;
use App\Filament\Widgets\StatsOverviewWidget;
use App\Filament\Widgets\TodaysScheduleWidget;
use App\Models\Appointment;
use App\Models\AppointmentType;
use App\Models\Encounter;
use App\Models\User;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('dashboard widgets are accessible to staff and admin', function (string $role) {
    $user = User::factory()->{$role}()->create();

    $this->actingAs($user);

    Livewire::test(StatsOverviewWidget::class)->assertSuccessful();
    Livewire::test(TodaysScheduleWidget::class)->assertSuccessful();
})->with(['staff', 'admin']);

test('dashboard prioritizes clinical workflow stats', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin);

    Livewire::test(StatsOverviewWidget::class)
        ->assertSee('Needs attention')
        ->assertSee('Appointment Requests')
        ->assertSee('Patients Waiting')
        ->assertSee('Active Encounters')
        ->assertSee('Optical Orders Ready')
        ->assertSee('Updated at')
        ->assertDontSee('Quotations Awaiting Decision')
        ->assertDontSee('Balances Due')
        ->assertDontSee('Low Stock Items')
        ->assertDontSee('Job order');

    $widget = Livewire::test(StatsOverviewWidget::class)->instance();
    $stats = (fn (): array => $this->getStats())->call($widget);

    expect($stats)->toHaveCount(4);
});

test('secondary admin issues are grouped into a collapsed section', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin);

    $component = Livewire::test(OtherIssuesWidget::class)
        ->assertSee('Other issues')
        ->assertSee('Quotations Awaiting Decision')
        ->assertSee('Balances Due')
        ->assertSee('Low Stock Items');

    $section = $component->instance()->getSectionContentComponent();

    expect($section->isCollapsible())->toBeTrue()
        ->and($section->isCollapsed())->toBeTrue();
});

test('dashboard stats link to the corresponding operational work', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin);

    $component = Livewire::test(StatsOverviewWidget::class);
    $widget = $component->instance();
    $stats = (fn (): array => $this->getStats())->call($widget);
    $statsByLabel = collect($stats)->keyBy(
        fn (Stat $stat): string => (string) $stat->getLabel(),
    );

    expect($statsByLabel->get('Appointment Requests')?->getUrl())
        ->toStartWith(AppointmentRequestResource::getUrl('index'))
        ->and($statsByLabel->get('Patients Waiting')?->getUrl())
        ->toContain('activeTab=checked_in')
        ->and($statsByLabel->get('Active Encounters')?->getUrl())
        ->toBe(EncounterResource::getUrl('index', ['activeTab' => 'in_progress']))
        ->and($statsByLabel->get('Optical Orders Ready')?->getUrl())
        ->toStartWith(OpticalOrderResource::getUrl('index'))
        ->and($statsByLabel)->not->toHaveKey('Quotations Awaiting Decision');
});

test('optometrist dashboard data is scoped to assigned work', function () {
    $this->travelTo(Carbon::parse('2026-08-11 10:00:00'));

    $optometrist = User::factory()->optometrist()->create();
    $otherOptometrist = User::factory()->optometrist()->create();
    $assignedAppointment = Appointment::factory()->create([
        'optometrist_id' => $optometrist->id,
        'scheduled_at' => now()->addHour(),
    ]);
    $otherAppointment = Appointment::factory()->create([
        'optometrist_id' => $otherOptometrist->id,
        'scheduled_at' => now()->addHours(2),
    ]);
    Encounter::factory()->inProgress()->create(['optometrist_id' => $optometrist->id]);
    Encounter::factory()->inProgress()->create(['optometrist_id' => $otherOptometrist->id]);

    $this->actingAs($optometrist);

    $statsWidget = Livewire::test(StatsOverviewWidget::class)->instance();
    $stats = collect((fn (): array => $this->getStats())->call($statsWidget))->keyBy(
        fn (Stat $stat): string => (string) $stat->getLabel(),
    );

    expect($stats->get('My Appointments Today')?->getValue())->toBe('1')
        ->and($stats->get('My Active Encounters')?->getValue())->toBe('1')
        ->and($stats->get('My Active Encounters')?->getUrl())->toContain('optometrist%5D%5Bvalue%5D='.$optometrist->id);

    Livewire::test(TodaysScheduleWidget::class)
        ->assertCanSeeTableRecords([$assignedAppointment])
        ->assertCanNotSeeTableRecords([$otherAppointment]);
});

test('appointment trend excludes cancelled and no-show appointments', function () {
    $this->travelTo(Carbon::parse('2026-08-11 10:00:00'));
    Cache::clear();

    $admin = User::factory()->admin()->create();
    Appointment::factory()->create(['scheduled_at' => now()]);
    Appointment::factory()->cancelled()->create(['scheduled_at' => now()]);
    Appointment::factory()->noShow()->create(['scheduled_at' => now()]);

    $this->actingAs($admin);

    $widget = Livewire::test(AppointmentsChartWidget::class)->instance();
    $data = (fn (): array => $this->getData())->call($widget);

    expect($data['datasets'][0]['data'])->toHaveCount(30)
        ->and($data['datasets'][0]['data'][29])->toBe(1);
});

test('today schedule widget identifies patients and opens their appointments', function () {
    $this->travelTo(Carbon::parse('2026-08-11 10:00:00'));

    $admin = User::factory()->admin()->create();
    $appointmentType = AppointmentType::factory()->create([
        'name' => 'Comprehensive Eye Examination',
    ]);
    $appointment = Appointment::factory()->create([
        'appointment_type_id' => $appointmentType->id,
        'scheduled_at' => now()->addHour(),
    ]);
    $appointment->patient->update([
        'first_name' => 'Maria',
        'middle_name' => null,
        'last_name' => 'Santos',
    ]);
    $patientNumber = $appointment->patient->fresh()->patient_number;

    $this->actingAs($admin);

    $component = Livewire::test(TodaysScheduleWidget::class)
        ->assertCanSeeTableRecords([$appointment])
        ->assertSee('Maria Santos')
        ->assertSee($patientNumber)
        ->assertSee('Comprehensive Eye Examination')
        ->assertSee("Today's Patient Flow · 1 appointment")
        ->assertSee('View Full Schedule')
        ->assertDontSee('job order');

    expect($component->instance()->getTable()->getRecordUrl($appointment))
        ->toBe(AppointmentResource::getUrl('edit', ['record' => $appointment]));
});

test('dashboard uses conditional sections for each clinic role', function () {
    $staff = User::factory()->staff()->create();

    $this->actingAs($staff);

    Livewire::test(AdminDashboard::class)
        ->assertSee('New Appointment')
        ->assertSee('Ready for Pickup')
        ->assertDontSee('My Encounters');

    $staffStats = Livewire::test(StatsOverviewWidget::class)->instance();
    expect((fn (): array => $this->getStats())->call($staffStats))->toHaveCount(4);
    expect(AppointmentsChartWidget::canView())->toBeFalse();
    expect(OtherIssuesWidget::canView())->toBeFalse();

    $optometrist = User::factory()->optometrist()->create();

    $this->actingAs($optometrist);

    Livewire::test(AdminDashboard::class)
        ->assertSee('New Appointment')
        ->assertSee('My Encounters')
        ->assertDontSee('Ready for Pickup');

    Livewire::test(StatsOverviewWidget::class)
        ->assertSee('My Appointments Today')
        ->assertSee('My Active Encounters')
        ->assertDontSee('Low Stock Items');

    $optometristStats = Livewire::test(StatsOverviewWidget::class)->instance();
    expect((fn (): array => $this->getStats())->call($optometristStats))->toHaveCount(3);

    $owner = User::factory()->adminOptometrist()->create();

    $this->actingAs($owner);

    Livewire::test(AdminDashboard::class)
        ->assertSee('New Appointment')
        ->assertSee('My Encounters')
        ->assertSee('Ready for Pickup');

    $ownerStats = Livewire::test(StatsOverviewWidget::class)->instance();
    expect((fn (): array => $this->getStats())->call($ownerStats))->toHaveCount(4);
    expect(AppointmentsChartWidget::canView())->toBeTrue();
    expect(OtherIssuesWidget::canView())->toBeTrue();
});

test('encounter list statistics are not globally discovered on the dashboard', function () {
    expect(EncounterStatsWidget::isDiscovered())->toBeFalse();
});

test('appointment calendar modal shows the populated appointment type', function () {
    $admin = User::factory()->admin()->create();
    $appointmentType = AppointmentType::factory()->create([
        'name' => 'Calendar Eye Assessment',
    ]);
    $appointment = Appointment::factory()->create([
        'appointment_type_id' => $appointmentType->id,
    ]);

    $this->actingAs($admin);

    Livewire::test(AppointmentCalendarWidget::class)
        ->mountAction('viewAppointment', [
            'appointmentId' => $appointment->id,
        ])
        ->assertMountedActionModalSee('Appointment Type')
        ->assertMountedActionModalSee('Calendar Eye Assessment');
});
