<?php

use App\Actions\PatientAccounts\PrunePatientAccountData;
use App\Models\OtpChallenge;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow('2026-07-31 08:00:00');
    $this->seed(RoleSeeder::class);
});

afterEach(fn () => Carbon::setTestNow());

test('expired OTP challenges are pruned', function () {
    // Create an old consumed challenge
    OtpChallenge::factory()->consumed()->create([
        'created_at' => now()->subDays(31),
    ]);

    // Create a recent challenge (should not be pruned)
    OtpChallenge::factory()->pending()->create();

    $results = app(PrunePatientAccountData::class)->handle();

    expect($results['otp_challenges'])->toBe(1);
    expect(OtpChallenge::count())->toBe(1);
});

test('recent OTP challenges are not pruned', function () {
    OtpChallenge::factory()->pending()->create();

    $results = app(PrunePatientAccountData::class)->handle();

    expect($results['otp_challenges'])->toBe(0);
    expect(OtpChallenge::count())->toBe(1);
});

test('command runs successfully', function () {
    $this->artisan('patient-accounts:prune')
        ->assertSuccessful();
});
