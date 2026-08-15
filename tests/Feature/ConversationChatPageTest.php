<?php

use App\Filament\Resources\Conversations\Pages\ConversationChatPage;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Patient;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

test('conversation chat refreshes link status after the account becomes linked', function () {
    $admin = User::factory()->admin()->create();
    $account = User::factory()->create([
        'first_name' => 'Unlinked',
        'last_name' => 'Account',
    ]);
    $patient = Patient::factory()->create([
        'first_name' => 'Linked',
        'middle_name' => null,
        'last_name' => 'Patient',
        'user_id' => null,
    ]);
    $conversation = Conversation::query()->create([
        'account_user_id' => $account->id,
        'patient_id' => null,
    ]);

    $this->actingAs($admin);

    $component = Livewire::test(ConversationChatPage::class)
        ->set('selectedConversationId', $conversation->id)
        ->assertSee('Unlinked account — general inquiry only');

    $patient->update(['user_id' => $account->id]);
    $conversation->update(['patient_id' => $patient->id]);

    $component
        ->call('$refresh')
        ->assertSee('Linked Patient')
        ->assertDontSee('Unlinked account — general inquiry only');
});

test('selecting a conversation stamps staff_last_read_at', function () {
    $admin = User::factory()->admin()->create();
    $patient = User::factory()->patient()->create();
    $conversation = Conversation::query()->create([
        'account_user_id' => $patient->id,
        'patient_id' => $patient->patient->id,
    ]);

    Message::factory()->count(3)->create([
        'conversation_id' => $conversation->id,
        'sender_id' => $patient->id,
    ]);

    $this->assertNull($conversation->fresh()->staff_last_read_at);

    $this->actingAs($admin);

    Livewire::test(ConversationChatPage::class)
        ->call('selectConversation', $conversation->id);

    $this->assertNotNull($conversation->fresh()->staff_last_read_at);
});

test('re-rendering without changing selection does not re-stamp watermark', function () {
    $admin = User::factory()->admin()->create();
    $patient = User::factory()->patient()->create();
    $conversation = Conversation::query()->create([
        'account_user_id' => $patient->id,
        'patient_id' => $patient->patient->id,
    ]);

    $this->actingAs($admin);

    $component = Livewire::test(ConversationChatPage::class)
        ->call('selectConversation', $conversation->id);

    $firstStamp = $conversation->fresh()->staff_last_read_at;

    // Simulate poll tick — re-render without changing selection
    $component->call('$refresh');

    $secondStamp = $conversation->fresh()->staff_last_read_at;

    expect($firstPass1 = $firstStamp->timestamp)->toBe($secondStamp->timestamp);
});

test('selecting a conversation with unread patient messages drops staff unread to zero', function () {
    $admin = User::factory()->admin()->create();
    $patient = User::factory()->patient()->create();
    $conversation = Conversation::query()->create([
        'account_user_id' => $patient->id,
        'patient_id' => $patient->patient->id,
    ]);

    Message::factory()->count(5)->create([
        'conversation_id' => $conversation->id,
        'sender_id' => $patient->id,
    ]);

    $this->actingAs($admin);

    Livewire::test(ConversationChatPage::class)
        ->call('selectConversation', $conversation->id);

    expect($conversation->fresh()->unreadForStaff())->toBe(0);
});
