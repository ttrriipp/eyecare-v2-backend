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

test('sidebar shows unread pill with count when there are unread patient messages', function () {
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

    $this->actingAs($admin);

    Livewire::test(ConversationChatPage::class)
        ->assertSee('3');
});

test('sidebar hides unread pill when count is zero', function () {
    $admin = User::factory()->admin()->create();
    $patient = User::factory()->patient()->create();
    $conversation = Conversation::query()->create([
        'account_user_id' => $patient->id,
        'patient_id' => $patient->patient->id,
        'staff_last_read_at' => now(),
    ]);

    // All messages are from staff — unread count is 0
    $staff = User::factory()->create();
    Message::factory()->count(5)->create([
        'conversation_id' => $conversation->id,
        'sender_id' => $staff->id,
    ]);

    $this->actingAs($admin);

    Livewire::test(ConversationChatPage::class)
        ->assertDontSee('unread-for-staff');
});

test('inbox orders by most recent message, not thread creation', function () {
    $admin = User::factory()->admin()->create();
    $patient1 = User::factory()->patient()->create();
    $patient2 = User::factory()->patient()->create();

    // Older thread with a recent message
    $conversation1 = Conversation::query()->create([
        'account_user_id' => $patient1->id,
        'patient_id' => $patient1->patient->id,
        'created_at' => now()->subWeek(),
    ]);
    Message::factory()->create([
        'conversation_id' => $conversation1->id,
        'sender_id' => $patient1->id,
        'body' => 'Recent message in old thread',
        'created_at' => now(),
    ]);

    // Newer thread with an older message
    $conversation2 = Conversation::query()->create([
        'account_user_id' => $patient2->id,
        'patient_id' => $patient2->patient->id,
        'created_at' => now(),
    ]);
    Message::factory()->create([
        'conversation_id' => $conversation2->id,
        'sender_id' => $patient2->id,
        'body' => 'Old message in new thread',
        'created_at' => now()->subHour(),
    ]);

    $this->actingAs($admin);

    $component = Livewire::test(ConversationChatPage::class);

    // The older thread with the more recent message should appear first
    $html = $component->html();
    $pos1 = strpos($html, 'Recent message in old thread');
    $pos2 = strpos($html, 'Old message in new thread');

    expect($pos1)->toBeLessThan($pos2);
});

test('empty thread still renders without error', function () {
    $admin = User::factory()->admin()->create();
    $patient = User::factory()->patient()->create();
    Conversation::query()->create([
        'account_user_id' => $patient->id,
        'patient_id' => $patient->patient->id,
    ]);

    $this->actingAs($admin);

    Livewire::test(ConversationChatPage::class)
        ->assertSuccessful();
});

test('archived threads are hidden from the default inbox', function () {
    $admin = User::factory()->admin()->create();
    $patient = User::factory()->patient()->create();
    $conversation = Conversation::query()->create([
        'account_user_id' => $patient->id,
        'patient_id' => $patient->patient->id,
        'inbox_archived_at' => now(),
    ]);

    $this->actingAs($admin);

    Livewire::test(ConversationChatPage::class)
        ->assertDontSee($patient->patient->full_name);
});

test('archived threads appear when showArchived toggle is on', function () {
    $admin = User::factory()->admin()->create();
    $patient = User::factory()->patient()->create();
    $conversation = Conversation::query()->create([
        'account_user_id' => $patient->id,
        'patient_id' => $patient->patient->id,
        'inbox_archived_at' => now(),
    ]);

    $this->actingAs($admin);

    Livewire::test(ConversationChatPage::class)
        ->set('showArchived', true)
        ->assertSee($patient->patient->full_name);
});

test('archive action removes thread from default inbox', function () {
    $admin = User::factory()->admin()->create();
    $patient = User::factory()->patient()->create();
    $conversation = Conversation::query()->create([
        'account_user_id' => $patient->id,
        'patient_id' => $patient->patient->id,
    ]);

    $this->actingAs($admin);

    Livewire::test(ConversationChatPage::class)
        ->call('archiveConversation', $conversation->id);

    expect($conversation->fresh()->isInboxArchived())->toBeTrue();
});

test('restore action returns thread to default inbox', function () {
    $admin = User::factory()->admin()->create();
    $patient = User::factory()->patient()->create();
    $conversation = Conversation::query()->create([
        'account_user_id' => $patient->id,
        'patient_id' => $patient->patient->id,
        'inbox_archived_at' => now(),
    ]);

    $this->actingAs($admin);

    Livewire::test(ConversationChatPage::class)
        ->set('showArchived', true)
        ->call('restoreConversation', $conversation->id);

    expect($conversation->fresh()->isInboxArchived())->toBeFalse();
});

test('new message on archived thread auto-restores to inbox', function () {
    $patient = User::factory()->patient()->create();
    $conversation = Conversation::query()->create([
        'account_user_id' => $patient->id,
        'patient_id' => $patient->patient->id,
        'inbox_archived_at' => now(),
    ]);

    expect($conversation->isInboxArchived())->toBeTrue();

    // Send a new message — Message::booted() auto-restores
    $conversation->messages()->create([
        'sender_id' => $patient->id,
        'body' => 'New message',
    ]);

    expect($conversation->fresh()->isInboxArchived())->toBeFalse();
});
