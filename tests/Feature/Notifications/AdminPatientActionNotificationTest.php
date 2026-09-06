<?php

use App\Actions\Appointments\CancelAppointment;
use App\Actions\Notifications\NotifyAdminUsers;
use App\Enums\JobOrderStatus;
use App\Filament\Resources\AppointmentRequests\AppointmentRequestResource;
use App\Filament\Resources\Appointments\AppointmentResource;
use App\Filament\Resources\Conversations\ConversationResource;
use App\Filament\Resources\FrameRatings\FrameRatingResource;
use App\Filament\Resources\PatientLinkRequests\PatientLinkRequestResource;
use App\Filament\Resources\VisitRatings\VisitRatingResource;
use App\Models\Appointment;
use App\Models\AppointmentRequest;
use App\Models\AppointmentType;
use App\Models\Brand;
use App\Models\FrameRating;
use App\Models\JobOrder;
use App\Models\JobOrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Notifications\AdminDatabaseNotification;
use Database\Seeders\AppointmentStatusSeeder;
use Database\Seeders\AppointmentTypeSeeder;
use Database\Seeders\ClinicHoursSeeder;
use Database\Seeders\NotificationStatusSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow('2026-07-10 08:00:00');
    $this->seed(RoleSeeder::class);
    $this->seed(AppointmentStatusSeeder::class);
    $this->seed(AppointmentTypeSeeder::class);
    $this->seed(ClinicHoursSeeder::class);
    $this->seed(NotificationStatusSeeder::class);
});

afterEach(fn () => Carbon::setTestNow());

function assertAdminActionNotification(User $recipient, string $title, string $url): void
{
    $notification = $recipient->fresh()->unreadNotifications->sole();

    expect($notification->data)
        ->toHaveKey('title', $title)
        ->toHaveKey('status')
        ->and($notification->data['actions'])->toHaveCount(1)
        ->and($notification->data['actions'][0]['label'])->toBe('View')
        ->and($notification->data['actions'][0]['url'])->toBe($url)
        ->and($notification->data['actions'][0]['shouldMarkAsRead'])->toBeTrue();
}

test('admin notification reaches active operational users with an actionable payload', function () {
    $admin = User::factory()->admin()->create();
    $staff = User::factory()->staff()->create();
    $inactiveAdmin = User::factory()->admin()->create(['is_active' => false]);
    $inactiveStaff = User::factory()->staff()->create(['is_active' => false]);
    $optometrist = User::factory()->optometrist()->create();
    $patient = User::factory()->patient()->create();

    app(NotifyAdminUsers::class)->handle(new AdminDatabaseNotification(
        title: 'New Appointment Request',
        body: 'Maria Santos submitted appointment request APR-2026-000001.',
        icon: 'heroicon-o-inbox-arrow-down',
        status: 'info',
        url: 'http://localhost/admin/appointment-requests/1',
    ));

    foreach ([$admin, $staff] as $recipient) {
        $notification = $recipient->fresh()->unreadNotifications->sole();

        expect($notification->data)
            ->toHaveKey('title', 'New Appointment Request')
            ->toHaveKey('body', 'Maria Santos submitted appointment request APR-2026-000001.')
            ->toHaveKey('icon', 'heroicon-o-inbox-arrow-down')
            ->toHaveKey('status', 'info')
            ->and($notification->data['actions'])->toHaveCount(1)
            ->and($notification->data['actions'][0]['label'])->toBe('View')
            ->and($notification->data['actions'][0]['url'])->toBe('http://localhost/admin/appointment-requests/1')
            ->and($notification->data['actions'][0]['shouldMarkAsRead'])->toBeTrue();
    }

    foreach ([$inactiveAdmin, $inactiveStaff, $optometrist, $patient] as $nonRecipient) {
        expect($nonRecipient->fresh()->notifications)->toBeEmpty();
    }
});

test('notification delivery failures do not escape the patient mutation path', function () {
    Notification::shouldReceive('send')
        ->once()
        ->andThrow(new RuntimeException('notification queue unavailable'));

    expect(fn () => app(NotifyAdminUsers::class)->handle(new AdminDatabaseNotification(
        title: 'Test notification',
        body: 'Test body',
        icon: 'heroicon-o-bell',
        status: 'info',
        url: 'http://localhost/admin',
    )))->not->toThrow(RuntimeException::class);
});

test('new appointment requests notify staff with a schedule review link', function () {
    $admin = User::factory()->admin()->create();
    $patient = User::factory()->patient()->create([
        'first_name' => 'Ana',
        'last_name' => 'Reyes',
    ]);

    $this->actingAs($patient)
        ->postJson('/api/v1/appointment-requests', [
            'appointment_type_id' => AppointmentType::query()->where('name', 'New Patient')->value('id'),
            'scheduled_at' => '2026-07-13T10:00:00+08:00',
            'reason_for_visit' => 'Blurred vision',
        ])
        ->assertCreated();

    $request = AppointmentRequest::query()->latest('id')->firstOrFail();

    assertAdminActionNotification(
        $admin,
        'New Appointment Request',
        AppointmentRequestResource::getUrl('schedule', ['record' => $request], panel: 'admin'),
    );

    expect($admin->fresh()->unreadNotifications->sole()->data['body'])
        ->toContain($request->patient->full_name)
        ->toContain($request->request_number);
});

test('cancelled pending appointment requests notify staff', function () {
    $admin = User::factory()->admin()->create();
    $patient = User::factory()->patient()->create();
    $request = AppointmentRequest::factory()->create([
        'user_id' => $patient->id,
        'patient_id' => $patient->patient->id,
        'expires_at' => now()->addDay(),
    ]);

    $this->actingAs($patient)
        ->postJson("/api/v1/appointment-requests/{$request->id}/cancel")
        ->assertOk();

    assertAdminActionNotification(
        $admin,
        'Appointment Request Cancelled',
        AppointmentRequestResource::getUrl('view', ['record' => $request], panel: 'admin'),
    );
});

test('new patient link requests notify staff', function () {
    $admin = User::factory()->admin()->create();
    $patientAccount = User::factory()->create([
        'first_name' => 'Maria',
        'last_name' => 'Santos',
    ]);

    $this->actingAs($patientAccount)
        ->postJson('/api/v1/patient-link-requests')
        ->assertCreated();

    $linkRequest = $patientAccount->fresh()->linkRequests()->latest('id')->firstOrFail();

    assertAdminActionNotification(
        $admin,
        'New Patient Link Request',
        PatientLinkRequestResource::getUrl('view', ['record' => $linkRequest], panel: 'admin'),
    );
});

test('repeated pending patient link submissions do not duplicate the staff alert', function () {
    $admin = User::factory()->admin()->create();
    $patientAccount = User::factory()->create();

    $this->actingAs($patientAccount)
        ->postJson('/api/v1/patient-link-requests')
        ->assertCreated();

    $this->actingAs($patientAccount)
        ->postJson('/api/v1/patient-link-requests')
        ->assertCreated();

    expect($admin->fresh()->unreadNotifications)->toHaveCount(1);
});

test('patient messages notify staff with the conversation inbox link', function () {
    $admin = User::factory()->admin()->create();
    $patient = User::factory()->patient()->create();

    $this->actingAs($patient)
        ->postJson('/api/v1/conversation/messages', ['body' => 'I need help with my appointment.'])
        ->assertCreated();

    assertAdminActionNotification(
        $admin,
        'New Message',
        ConversationResource::getUrl('index', panel: 'admin'),
    );

    expect($admin->fresh()->unreadNotifications->sole()->data['body'])
        ->not->toContain('I need help with my appointment.');
});

test('patient appointment cancellations notify staff with the appointment link', function () {
    $admin = User::factory()->admin()->create();
    $patient = User::factory()->patient()->create();
    $appointment = Appointment::factory()->create([
        'patient_id' => $patient->patient->id,
        'scheduled_at' => '2026-07-13 10:00:00',
    ]);

    $this->actingAs($patient)
        ->postJson("/api/v1/appointments/{$appointment->id}/cancel", [
            'reason_category' => 'patient_request',
        ])
        ->assertOk();

    assertAdminActionNotification(
        $admin,
        'Appointment Cancelled by Patient',
        AppointmentResource::getUrl('edit', ['record' => $appointment], panel: 'admin'),
    );
});

test('patient appointment reschedules notify staff with the appointment link', function () {
    $admin = User::factory()->admin()->create();
    $patient = User::factory()->patient()->create();
    $optometrist = User::factory()->optometrist()->create();
    $appointment = Appointment::factory()->create([
        'patient_id' => $patient->patient->id,
        'optometrist_id' => $optometrist->id,
        'scheduled_at' => '2026-07-13 10:00:00',
    ]);

    $this->actingAs($patient)
        ->postJson("/api/v1/appointments/{$appointment->id}/reschedule", [
            'scheduled_at' => '2026-07-14T11:00:00+08:00',
        ])
        ->assertOk();

    assertAdminActionNotification(
        $admin,
        'Appointment Rescheduled by Patient',
        AppointmentResource::getUrl('edit', ['record' => $appointment], panel: 'admin'),
    );
});

test('new and changed low visit ratings notify staff while identical retries stay silent', function () {
    $admin = User::factory()->admin()->create();
    $patient = User::factory()->patient()->create();
    $appointment = Appointment::factory()->fulfilled()->create([
        'patient_id' => $patient->patient->id,
    ]);

    $this->actingAs($patient)
        ->postJson("/api/v1/appointments/{$appointment->id}/rating", [
            'rating' => 2,
            'comment' => 'Could be better.',
        ])
        ->assertCreated();

    $rating = $appointment->visitRating()->firstOrFail();
    assertAdminActionNotification(
        $admin,
        'Low Visit Rating',
        VisitRatingResource::getUrl('view', ['record' => $rating], panel: 'admin'),
    );

    expect($admin->fresh()->unreadNotifications->sole()->data['body'])
        ->not->toContain('Could be better.');

    $this->actingAs($patient)
        ->postJson("/api/v1/appointments/{$appointment->id}/rating", [
            'rating' => 2,
            'comment' => 'Could be better.',
        ])
        ->assertOk();

    expect($admin->fresh()->unreadNotifications)->toHaveCount(1);

    $this->actingAs($patient)
        ->postJson("/api/v1/appointments/{$appointment->id}/rating", [
            'rating' => 1,
            'comment' => 'Still had an issue.',
        ])
        ->assertOk();

    expect($admin->fresh()->unreadNotifications)->toHaveCount(2);
});

test('high visit ratings and clinic appointment cancellations stay silent', function () {
    $admin = User::factory()->admin()->create();
    $patient = User::factory()->patient()->create();
    $appointment = Appointment::factory()->fulfilled()->create([
        'patient_id' => $patient->patient->id,
    ]);

    $this->actingAs($patient)
        ->postJson("/api/v1/appointments/{$appointment->id}/rating", ['rating' => 5])
        ->assertCreated();

    expect($admin->fresh()->notifications)->toBeEmpty();

    $scheduledAppointment = Appointment::factory()->create([
        'patient_id' => $patient->patient->id,
        'scheduled_at' => '2026-07-13 10:00:00',
    ]);

    app(CancelAppointment::class)->handle(
        appointment: $scheduledAppointment,
        initiator: 'clinic',
        actor: $admin,
        reasonCategory: 'schedule_conflict',
    );

    expect($admin->fresh()->notifications)->toBeEmpty();
});

test('new and changed low frame ratings notify staff while high ratings stay silent', function () {
    $admin = User::factory()->admin()->create();
    $patient = User::factory()->patient()->create();
    $brand = Brand::factory()->create();
    $product = Product::factory()->create([
        'brand_id' => $brand->id,
        'product_type' => 'frame',
    ]);
    $variant = ProductVariant::factory()->create(['product_id' => $product->id]);
    $jobOrder = JobOrder::factory()->create([
        'patient_id' => $patient->patient->id,
        'status' => JobOrderStatus::Dispensed,
    ]);
    $item = JobOrderItem::factory()->create([
        'job_order_id' => $jobOrder->id,
        'product_variant_id' => $variant->id,
    ]);

    $this->actingAs($patient)
        ->postJson("/api/v1/optical-order-items/{$item->id}/rating", [
            'rating' => 2,
            'comment' => 'The frame feels uncomfortable.',
        ])
        ->assertCreated();

    $rating = FrameRating::query()
        ->where('patient_id', $patient->patient->id)
        ->firstOrFail();
    assertAdminActionNotification(
        $admin,
        'Low Frame Rating',
        FrameRatingResource::getUrl('edit', ['record' => $rating], panel: 'admin'),
    );

    expect($admin->fresh()->unreadNotifications->sole()->data['body'])
        ->not->toContain('The frame feels uncomfortable.');

    $this->actingAs($patient)
        ->postJson("/api/v1/optical-order-items/{$item->id}/rating", [
            'rating' => 5,
            'comment' => 'It fits better now.',
        ])
        ->assertCreated();

    expect($admin->fresh()->unreadNotifications)->toHaveCount(1);
});
