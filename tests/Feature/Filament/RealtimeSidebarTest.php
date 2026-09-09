<?php

use App\Filament\Support\RealtimeSidebar;
use App\Models\Appointment;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Livewire\livewire;

uses(RefreshDatabase::class);

test('admin sidebar polls for fresh navigation badges', function () {
    $html = $this->actingAs(User::factory()->admin()->create())
        ->get('/admin')
        ->assertSuccessful()
        ->getContent();

    expect($html)->toMatch(
        '/<div(?=[^>]*data-realtime-sidebar)(?=[^>]*wire:poll\.5s\.keep-alive="refresh")(?=[^>]*class="contents")[^>]*>/',
    );
});

test('sidebar refreshes the appointments badge when today appointments change', function () {
    $this->actingAs(User::factory()->admin()->create());
    Filament::setCurrentPanel('admin');
    Filament::bootCurrentPanel();

    Appointment::factory()->create([
        'scheduled_at' => today()->setTime(10, 0),
    ]);

    $sidebar = livewire(RealtimeSidebar::class)
        ->assertSee('Appointments')
        ->assertSee('1');

    Appointment::factory()->create([
        'scheduled_at' => today()->setTime(11, 0),
    ]);

    $sidebar
        ->call('refresh')
        ->assertSee('Appointments')
        ->assertSee('2');
});

test('sidebar refreshes the messages badge when a patient message arrives', function () {
    $this->actingAs(User::factory()->admin()->create());
    Filament::setCurrentPanel('admin');
    Filament::bootCurrentPanel();

    $sidebar = livewire(RealtimeSidebar::class)
        ->assertSee('Messages');

    $patient = User::factory()->patient()->create();
    $conversation = Conversation::query()->create([
        'account_user_id' => $patient->id,
        'patient_id' => $patient->patient->id,
    ]);

    Message::factory()->create([
        'conversation_id' => $conversation->id,
        'sender_id' => $patient->id,
    ]);

    $sidebar
        ->call('refresh')
        ->assertSee('Messages')
        ->assertSee('1');
});
