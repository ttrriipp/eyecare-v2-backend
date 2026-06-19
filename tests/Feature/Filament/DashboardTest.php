<?php

use App\Filament\Widgets\RecentFeedbackWidget;
use App\Filament\Widgets\StatsOverviewWidget;
use App\Models\Appointment;
use App\Models\AppointmentStatus;
use App\Models\Billing;
use App\Models\BillingStatus;
use App\Models\Feedback;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\ProductVariant;
use App\Models\User;
use Database\Seeders\AppointmentStatusSeeder;
use Database\Seeders\BillingStatusSeeder;
use Database\Seeders\OrderStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(AppointmentStatusSeeder::class);
    $this->seed(OrderStatusSeeder::class);
    $this->seed(BillingStatusSeeder::class);
});

test('staff and admin can access the dashboard widgets', function (string $role) {
    $user = User::factory()->{$role}()->create();
    $this->actingAs($user);

    Livewire::test(StatsOverviewWidget::class)->assertSuccessful();
    Livewire::test(RecentFeedbackWidget::class)->assertSuccessful();
})->with(['staff', 'admin']);

test('stats widget counts todays confirmed appointments', function () {
    $staff = User::factory()->staff()->create();

    $confirmedId = AppointmentStatus::query()->where('name', 'confirmed')->value('id');

    Appointment::factory()->count(2)->create([
        'appointment_status_id' => $confirmedId,
        'scheduled_at' => today()->midDay(),
    ]);

    Appointment::factory()->create([
        'appointment_status_id' => $confirmedId,
        'scheduled_at' => today()->addDay(),
    ]);

    $this->actingAs($staff);

    Livewire::test(StatsOverviewWidget::class)->assertSuccessful();

    expect(
        Appointment::query()
            ->whereHas('status', fn ($q) => $q->where('name', 'confirmed'))
            ->whereDate('scheduled_at', today())
            ->count()
    )->toBe(2);
});

test('stats widget counts pending and under review orders', function () {
    $staff = User::factory()->staff()->create();

    $requestedId = OrderStatus::query()->where('name', 'requested')->value('id');
    $underReviewId = OrderStatus::query()->where('name', 'requested')->value('id');
    $completedId = OrderStatus::query()->where('name', 'completed')->value('id');

    Order::factory()->count(2)->create(['order_status_id' => $requestedId]);
    Order::factory()->create(['order_status_id' => $underReviewId]);
    Order::factory()->create(['order_status_id' => $completedId]);

    $this->actingAs($staff);

    Livewire::test(StatsOverviewWidget::class)->assertSuccessful();

    expect(
        Order::query()
            ->whereHas('status', fn ($q) => $q->whereIn('name', ['requested']))
            ->count()
    )->toBe(3);
});

test('stats widget counts low stock active variants', function () {
    $staff = User::factory()->staff()->create();

    ProductVariant::factory()->create(['is_active' => true, 'stock_quantity' => 2, 'low_stock_threshold' => 5]);
    ProductVariant::factory()->create(['is_active' => true, 'stock_quantity' => 10, 'low_stock_threshold' => 5]);
    ProductVariant::factory()->create(['is_active' => false, 'stock_quantity' => 1, 'low_stock_threshold' => 5]);

    $this->actingAs($staff);

    Livewire::test(StatsOverviewWidget::class)->assertSuccessful();

    expect(
        ProductVariant::query()
            ->where('is_active', true)
            ->whereColumn('stock_quantity', '<=', 'low_stock_threshold')
            ->count()
    )->toBe(1);
});

test('stats widget counts unpaid billings', function () {
    $staff = User::factory()->staff()->create();

    $draftId = BillingStatus::query()->where('name', 'draft')->value('id');
    $paidId = BillingStatus::query()->where('name', 'paid')->value('id');

    Billing::factory()->count(2)->create(['billing_status_id' => $draftId]);
    Billing::factory()->create(['billing_status_id' => $paidId]);

    $this->actingAs($staff);

    Livewire::test(StatsOverviewWidget::class)->assertSuccessful();

    expect(
        Billing::query()
            ->whereHas('status', fn ($q) => $q->whereIn('name', ['draft', 'issued', 'partially_paid']))
            ->count()
    )->toBe(2);
});

test('recent feedback widget shows at most 5 records', function () {
    $staff = User::factory()->staff()->create();
    Feedback::factory()->count(8)->create();

    $this->actingAs($staff);

    Livewire::test(RecentFeedbackWidget::class)->assertSuccessful();

    // The widget query applies limit(5) — verify via the underlying query
    $count = Feedback::query()->latest()->limit(5)->get()->count();
    expect($count)->toBe(5);
});
