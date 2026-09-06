<?php

use App\Enums\ArAssetStatus;
use App\Models\Appointment;
use App\Models\AppointmentRequest;
use App\Models\ArAsset;
use App\Models\BillingRecord;
use App\Models\Conversation;
use App\Models\Encounter;
use App\Models\JobOrder;
use App\Models\Patient;
use App\Models\PatientAccountContact;
use App\Models\PilotParticipantAccount;
use App\Models\ProductVariant;
use App\Models\Quotation;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CapstonePilotSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'ar.assets.base_url' => 'https://cdn.example.com',
        'ar.assets.quarantine_disk' => 'ar_quarantine',
        'ar.assets.published_disk' => 'ar_published',
    ]);
    Storage::fake('ar_quarantine');
    Storage::fake('ar_published');
});

test('pilot seeder creates only approved reference data and one published synthetic AR asset', function (): void {
    $this->seed(CapstonePilotSeeder::class);

    $asset = ArAsset::query()->where('status', ArAssetStatus::Published)->sole();
    $variant = ProductVariant::query()->where('sku', 'FRM-ANTHOS-MB1399A-C4')->firstOrFail();
    $assetActor = User::query()->where('email', 'pilot-ar-seeder@invalid.test')->firstOrFail();

    expect(Role::query()->pluck('name')->sort()->values()->all())->toBe([
        Role::Admin,
        Role::Optometrist,
        Role::Patient,
        Role::Staff,
    ])
        ->and(User::query()->whereIn('email', [
            'owner@eyecare.test',
            'admin@eyecare.test',
            'optometrist@eyecare.test',
            'staff@eyecare.test',
            'customer@eyecare.test',
        ])->count())->toBe(0)
        ->and(Patient::query()->count())->toBe(0)
        ->and(PatientAccountContact::query()->count())->toBe(0)
        ->and(PilotParticipantAccount::query()->count())->toBe(0)
        ->and(Appointment::query()->count())->toBe(0)
        ->and(AppointmentRequest::query()->count())->toBe(0)
        ->and(Encounter::query()->count())->toBe(0)
        ->and(Quotation::query()->count())->toBe(0)
        ->and(JobOrder::query()->count())->toBe(0)
        ->and(BillingRecord::query()->count())->toBe(0)
        ->and(Conversation::query()->count())->toBe(0)
        ->and($asset->status)->toBe(ArAssetStatus::Published)
        ->and($asset->isPatientReady())->toBeTrue()
        ->and($variant->published_ar_asset_id)->toBe($asset->id)
        ->and($assetActor->password)->toBeNull()
        ->and($assetActor->is_active)->toBeTrue()
        ->and($assetActor->roles->pluck('name')->all())->toBe([Role::Staff]);

    Storage::disk('ar_published')->assertExists($asset->published_path);
});

test('pilot seeder is idempotent and does not expand synthetic data on rerun', function (): void {
    $this->seed(CapstonePilotSeeder::class);
    $countsBefore = [
        'users' => User::query()->count(),
        'products' => ProductVariant::query()->count(),
        'assets' => ArAsset::query()->count(),
    ];
    $assetId = ArAsset::query()->value('id');

    $this->seed(CapstonePilotSeeder::class);

    expect([
        'users' => User::query()->count(),
        'products' => ProductVariant::query()->count(),
        'assets' => ArAsset::query()->count(),
    ])->toBe($countsBefore)
        ->and(ArAsset::query()->value('id'))->toBe($assetId)
        ->and(User::query()->where('email', 'pilot-ar-seeder@invalid.test')->count())->toBe(1);
});
