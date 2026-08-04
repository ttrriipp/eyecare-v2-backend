<?php

use App\Actions\PatientAccounts\IssuePatientInvitation;
use App\Jobs\DeliverPatientInvitation;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
});

test('local SMS delivery logs the invitation code for development testing', function () {
    $patient = Patient::factory()->create(['phone' => '09171234567']);
    $staff = User::factory()->staff()->create();

    $invitation = app(IssuePatientInvitation::class)->handle(
        patient: $patient,
        channel: 'phone',
        sender: $staff,
    );

    Log::spy();

    (new DeliverPatientInvitation($invitation->id))->handle();

    Log::shouldHaveReceived('info')
        ->with('SMS invitation delivery (development only)', [
            'invitation_id' => $invitation->id,
            'masked_phone' => '+63***4567',
            'invitation_code' => $invitation->invitation_code,
        ]);
});

test('production SMS delivery never logs the invitation code', function () {
    $patient = Patient::factory()->create(['phone' => '09171234567']);
    $staff = User::factory()->staff()->create();

    $invitation = app(IssuePatientInvitation::class)->handle(
        patient: $patient,
        channel: 'phone',
        sender: $staff,
    );

    Log::spy();
    app()->instance('env', 'production');

    try {
        (new DeliverPatientInvitation($invitation->id))->handle();
    } finally {
        app()->instance('env', 'testing');
    }

    Log::shouldHaveReceived('info')
        ->with('SMS invitation delivery not yet implemented', [
            'invitation_id' => $invitation->id,
            'masked_phone' => '+63***4567',
        ]);
});
