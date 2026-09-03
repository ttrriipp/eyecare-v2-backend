<?php

use App\Filament\Resources\Conversations\ConversationResource;
use App\Filament\Resources\Conversations\Pages\ConversationChatPage;
use App\Filament\Resources\Patients\PatientResource;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\Patient;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

test('staff messaging page uses messages as its user-facing title', function () {
    expect(ConversationResource::getNavigationLabel())->toBe('Messages')
        ->and(ConversationResource::getPluralModelLabel())->toBe('Messages')
        ->and(ConversationResource::getBreadcrumb())->toBe('Messages')
        ->and((new ConversationChatPage)->getTitle())->toBe('Messages');
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

test('staff chat exposes view and download links for pdf attachments', function () {
    Storage::fake('local');

    $admin = User::factory()->admin()->create();
    $patient = User::factory()->patient()->create();
    $conversation = Conversation::query()->create([
        'account_user_id' => $patient->id,
        'patient_id' => $patient->patient->id,
    ]);
    $message = Message::factory()->create([
        'conversation_id' => $conversation->id,
        'sender_id' => $patient->id,
        'body' => 'Attachment',
    ]);
    $attachment = MessageAttachment::factory()->create([
        'message_id' => $message->id,
        'original_name' => 'nw4d_g2_chapter4_draft.pdf',
        'mime_type' => 'application/pdf',
    ]);

    Storage::disk('local')->put($attachment->file_path, 'private-file');

    $this->actingAs($admin);

    $html = Livewire::test(ConversationChatPage::class)
        ->set('selectedConversationId', $conversation->id)
        ->html();

    expect($html)
        ->toContain($attachment->original_name)
        ->toContain(route('attachments.preview', $attachment))
        ->toContain(route('attachments.download', $attachment))
        ->toContain('data-message-bubble')
        ->toContain('data-message-attachments')
        ->not->toContain('data-message-body')
        ->toContain('View')
        ->toContain('Download');
});

test('staff chat shows a seen indicator on the last read staff message', function () {
    $admin = User::factory()->admin()->create();
    $patient = User::factory()->patient()->create();
    $conversation = Conversation::query()->create([
        'account_user_id' => $patient->id,
        'patient_id' => $patient->patient->id,
    ]);
    $readAt = now()->subMinutes(5);
    $message = Message::factory()->create([
        'conversation_id' => $conversation->id,
        'sender_id' => $admin->id,
        'body' => 'Your prescription is ready for pickup.',
        'read_at' => $readAt,
    ]);

    $this->actingAs($admin);

    Livewire::test(ConversationChatPage::class)
        ->set('selectedConversationId', $conversation->id)
        ->assertSee('Seen · '.$readAt->format('g:i a'));
});

test('staff chat hides the seen indicator when the last staff message is unread', function () {
    $admin = User::factory()->admin()->create();
    $patient = User::factory()->patient()->create();
    $conversation = Conversation::query()->create([
        'account_user_id' => $patient->id,
        'patient_id' => $patient->patient->id,
    ]);
    Message::factory()->create([
        'conversation_id' => $conversation->id,
        'sender_id' => $admin->id,
        'body' => 'Your prescription is ready for pickup.',
        'read_at' => null,
    ]);

    $this->actingAs($admin);

    Livewire::test(ConversationChatPage::class)
        ->set('selectedConversationId', $conversation->id)
        ->assertDontSee('Seen ·', escape: false);
});

test('seen indicator only appears once, on the most recent read staff message', function () {
    $admin = User::factory()->admin()->create();
    $patient = User::factory()->patient()->create();
    $conversation = Conversation::query()->create([
        'account_user_id' => $patient->id,
        'patient_id' => $patient->patient->id,
    ]);
    Message::factory()->create([
        'conversation_id' => $conversation->id,
        'sender_id' => $admin->id,
        'body' => 'First reply',
        'read_at' => now()->subHour(),
        'created_at' => now()->subHour(),
    ]);
    Message::factory()->create([
        'conversation_id' => $conversation->id,
        'sender_id' => $admin->id,
        'body' => 'Second reply',
        'read_at' => now()->subMinutes(5),
        'created_at' => now()->subMinutes(5),
    ]);

    $this->actingAs($admin);

    $html = Livewire::test(ConversationChatPage::class)
        ->set('selectedConversationId', $conversation->id)
        ->html();

    expect(substr_count($html, 'Seen ·'))->toBe(1);
});

test('conversation header links to the patient record when linked', function () {
    $admin = User::factory()->admin()->create();
    $patient = User::factory()->patient()->create();
    $conversation = Conversation::query()->create([
        'account_user_id' => $patient->id,
        'patient_id' => $patient->patient->id,
    ]);

    $this->actingAs($admin);

    Livewire::test(ConversationChatPage::class)
        ->set('selectedConversationId', $conversation->id)
        ->assertSee('View patient record')
        ->assertSeeHtml(PatientResource::getUrl('edit', ['record' => $patient->patient->id]));
});

test('conversation header hides the patient record link when unlinked', function () {
    $admin = User::factory()->admin()->create();
    $account = User::factory()->create();
    $conversation = Conversation::query()->create([
        'account_user_id' => $account->id,
        'patient_id' => null,
    ]);

    $this->actingAs($admin);

    Livewire::test(ConversationChatPage::class)
        ->set('selectedConversationId', $conversation->id)
        ->assertDontSee('View patient record');
});

test('sending a reply over the character limit shows an inline error instead of failing silently', function () {
    $admin = User::factory()->admin()->create();
    $patient = User::factory()->patient()->create();
    $conversation = Conversation::query()->create([
        'account_user_id' => $patient->id,
        'patient_id' => $patient->patient->id,
    ]);

    $this->actingAs($admin);

    Livewire::test(ConversationChatPage::class)
        ->set('selectedConversationId', $conversation->id)
        ->set('replyBody', str_repeat('a', 5001))
        ->call('sendReply')
        ->assertHasErrors(['replyBody'])
        ->assertSee('reply-body-error', escape: false);

    expect(Message::where('conversation_id', $conversation->id)->count())->toBe(0);
});

test('conversation list search filters by patient name', function () {
    $admin = User::factory()->admin()->create();

    $liza = User::factory()->patient()->create();
    $liza->patient->update(['first_name' => 'Liza', 'middle_name' => null, 'last_name' => 'Mendoza']);
    Conversation::query()->create([
        'account_user_id' => $liza->id,
        'patient_id' => $liza->patient->id,
    ]);

    $ana = User::factory()->patient()->create();
    $ana->patient->update(['first_name' => 'Ana', 'middle_name' => null, 'last_name' => 'Garcia']);
    Conversation::query()->create([
        'account_user_id' => $ana->id,
        'patient_id' => $ana->patient->id,
    ]);

    $this->actingAs($admin);

    Livewire::test(ConversationChatPage::class)
        ->set('conversationFilter', 'liza')
        ->assertSee('Liza Mendoza')
        ->assertDontSee('Ana Garcia');
});

test('conversation list search shows a not-found message when nothing matches', function () {
    $admin = User::factory()->admin()->create();
    $patient = User::factory()->patient()->create();
    Conversation::query()->create([
        'account_user_id' => $patient->id,
        'patient_id' => $patient->patient->id,
    ]);

    $this->actingAs($admin);

    Livewire::test(ConversationChatPage::class)
        ->set('conversationFilter', 'nobody-matches-this')
        ->assertSee('No conversations match "nobody-matches-this".', escape: false);
});

test('jumping to a search result closes search and dispatches a scroll event', function () {
    $admin = User::factory()->admin()->create();
    $patient = User::factory()->patient()->create();
    $conversation = Conversation::query()->create([
        'account_user_id' => $patient->id,
        'patient_id' => $patient->patient->id,
    ]);
    $message = Message::factory()->create([
        'conversation_id' => $conversation->id,
        'sender_id' => $patient->id,
        'body' => 'A message worth finding',
    ]);

    $this->actingAs($admin);

    Livewire::test(ConversationChatPage::class)
        ->set('selectedConversationId', $conversation->id)
        ->set('showSearch', true)
        ->set('searchQuery', 'worth finding')
        ->call('jumpToMessage', $message->id)
        ->assertSet('showSearch', false)
        ->assertSet('searchQuery', '')
        ->assertDispatched('scroll-to-message', messageId: $message->id);
});

test('mark as unread resets the staff read watermark and returns to inbox', function () {
    $admin = User::factory()->admin()->create();
    $patient = User::factory()->patient()->create();
    $conversation = Conversation::query()->create([
        'account_user_id' => $patient->id,
        'patient_id' => $patient->patient->id,
        'staff_last_read_at' => now(),
    ]);
    Message::factory()->create([
        'conversation_id' => $conversation->id,
        'sender_id' => $patient->id,
    ]);

    $this->actingAs($admin);

    Livewire::test(ConversationChatPage::class)
        ->set('selectedConversationId', $conversation->id)
        ->call('markAsUnread')
        ->assertSet('selectedConversationId', null)
        ->assertNotified('Marked as unread');

    expect($conversation->fresh()->staff_last_read_at)->toBeNull();
});

test('a toast notifies staff when a different conversation gets a new message', function () {
    $admin = User::factory()->admin()->create();

    $patientA = User::factory()->patient()->create();
    $conversationA = Conversation::query()->create([
        'account_user_id' => $patientA->id,
        'patient_id' => $patientA->patient->id,
    ]);

    $patientB = User::factory()->patient()->create();
    $conversationB = Conversation::query()->create([
        'account_user_id' => $patientB->id,
        'patient_id' => $patientB->patient->id,
    ]);

    $this->actingAs($admin);

    $component = Livewire::test(ConversationChatPage::class)
        ->set('selectedConversationId', $conversationA->id);

    Message::factory()->create([
        'conversation_id' => $conversationB->id,
        'sender_id' => $patientB->id,
    ]);

    $component
        ->call('$refresh')
        ->assertNotified('New message in another conversation');
});

test('no toast fires when new activity happens in the currently open conversation', function () {
    $admin = User::factory()->admin()->create();
    $patient = User::factory()->patient()->create();
    $conversation = Conversation::query()->create([
        'account_user_id' => $patient->id,
        'patient_id' => $patient->patient->id,
    ]);

    $this->actingAs($admin);

    $component = Livewire::test(ConversationChatPage::class)
        ->set('selectedConversationId', $conversation->id);

    Message::factory()->create([
        'conversation_id' => $conversation->id,
        'sender_id' => $patient->id,
    ]);

    $component
        ->call('$refresh')
        ->assertNotNotified('New message in another conversation');
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

test('showArchived toggle shows only archived threads, not active ones too', function () {
    $admin = User::factory()->admin()->create();

    $archivedPatient = User::factory()->patient()->create();
    $archivedPatient->patient->update(['first_name' => 'Archived', 'middle_name' => null, 'last_name' => 'Thread']);
    Conversation::query()->create([
        'account_user_id' => $archivedPatient->id,
        'patient_id' => $archivedPatient->patient->id,
        'inbox_archived_at' => now(),
    ]);

    $activePatient = User::factory()->patient()->create();
    $activePatient->patient->update(['first_name' => 'Active', 'middle_name' => null, 'last_name' => 'Thread']);
    Conversation::query()->create([
        'account_user_id' => $activePatient->id,
        'patient_id' => $activePatient->patient->id,
    ]);

    $this->actingAs($admin);

    Livewire::test(ConversationChatPage::class)
        ->set('showArchived', true)
        ->assertSee('Archived Thread')
        ->assertDontSee('Active Thread');
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
        ->set('selectedConversationId', $conversation->id)
        ->callAction('archive');

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
