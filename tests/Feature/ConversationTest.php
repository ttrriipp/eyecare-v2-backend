<?php

use App\Filament\Resources\Conversations\Pages\ConversationChatPage;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

test('conversation api response uses patient_id not customer_id', function () {
    $patient = User::factory()->patient()->create();
    $conversation = Conversation::query()->create([
        'account_user_id' => $patient->id,
        'patient_id' => $patient->patient->id,
    ]);

    $this->actingAs($patient)
        ->getJson('/api/v1/conversation')
        ->assertSuccessful()
        ->assertJsonPath('data.patient_id', $patient->patient->id)
        ->assertJsonMissing(['customer_id']);
});

test('patient can access own conversation', function () {
    $patient = User::factory()->patient()->create();
    $conversation = Conversation::query()->create([
        'account_user_id' => $patient->id,
        'patient_id' => $patient->patient->id,
    ]);

    $this->actingAs($patient)
        ->getJson('/api/v1/conversation')
        ->assertSuccessful();
});

test('patient conversation messages do not include another patients conversation messages', function () {
    $patient1 = User::factory()->patient()->create();
    $patient2 = User::factory()->patient()->create();
    $conversation = Conversation::query()->create([
        'account_user_id' => $patient2->id,
        'patient_id' => $patient2->patient->id,
    ]);
    Message::factory()->create([
        'conversation_id' => $conversation->id,
        'sender_id' => $patient2->id,
        'body' => 'Other patient protected message',
    ]);

    $this->actingAs($patient1)
        ->getJson('/api/v1/conversation/messages')
        ->assertSuccessful()
        ->assertJsonCount(0, 'data')
        ->assertJsonMissing(['Other patient protected message']);
});

test('patient can download own conversation attachment through singular attachment route', function () {
    Storage::fake('local');

    $patient = User::factory()->patient()->create();
    $conversation = Conversation::query()->create([
        'account_user_id' => $patient->id,
        'patient_id' => $patient->patient->id,
    ]);
    $message = Message::factory()->create([
        'conversation_id' => $conversation->id,
        'sender_id' => $patient->id,
    ]);
    $attachment = MessageAttachment::factory()->create([
        'message_id' => $message->id,
        'file_path' => 'attachments/own-prescription.pdf',
        'original_name' => 'own-prescription.pdf',
        'mime_type' => 'application/pdf',
    ]);

    Storage::disk('local')->put($attachment->file_path, 'private-file');

    $this->actingAs($patient)
        ->get("/api/v1/conversation/attachments/{$attachment->id}")
        ->assertDownload('own-prescription.pdf');
});

test('patient cannot download another patients conversation attachment', function () {
    Storage::fake('local');

    $patient1 = User::factory()->patient()->create();
    $patient2 = User::factory()->patient()->create();
    $conversation = Conversation::query()->create([
        'account_user_id' => $patient2->id,
        'patient_id' => $patient2->patient->id,
    ]);
    $message = Message::factory()->create([
        'conversation_id' => $conversation->id,
        'sender_id' => $patient2->id,
    ]);
    $attachment = MessageAttachment::factory()->create([
        'message_id' => $message->id,
        'file_path' => 'attachments/other-prescription.pdf',
        'original_name' => 'other-prescription.pdf',
        'mime_type' => 'application/pdf',
    ]);

    Storage::disk('local')->put($attachment->file_path, 'private-file');

    $this->actingAs($patient1)
        ->get("/api/v1/conversation/attachments/{$attachment->id}")
        ->assertNotFound();
});

test('linked patient resolves exactly one conversation', function () {
    $patient = User::factory()->patient()->create();

    $this->actingAs($patient)
        ->getJson('/api/v1/conversation')
        ->assertSuccessful()
        ->assertJsonPath('data.patient_id', $patient->patient->id);

    // Second call returns same conversation
    $this->actingAs($patient)
        ->getJson('/api/v1/conversation')
        ->assertSuccessful()
        ->assertJsonPath('data.patient_id', $patient->patient->id);

    expect(Conversation::where('account_user_id', $patient->id)->count())->toBe(1);
});

test('linked conversation messages are ordered oldest first', function () {
    $patient = User::factory()->patient()->create();
    $conversation = Conversation::query()->create([
        'account_user_id' => $patient->id,
        'patient_id' => $patient->patient->id,
    ]);

    Message::factory()->create([
        'conversation_id' => $conversation->id,
        'sender_id' => $patient->id,
        'body' => 'First message',
    ]);

    Message::factory()->create([
        'conversation_id' => $conversation->id,
        'sender_id' => $patient->id,
        'body' => 'Second message',
    ]);

    $this->actingAs($patient)
        ->getJson('/api/v1/conversation/messages')
        ->assertSuccessful()
        ->assertJsonPath('data.0.body', 'First message')
        ->assertJsonPath('data.1.body', 'Second message');
});

test('cross-account attachment access returns not found', function () {
    Storage::fake('local');

    $patient1 = User::factory()->patient()->create();
    $patient2 = User::factory()->patient()->create();
    $conversation = Conversation::query()->create([
        'account_user_id' => $patient2->id,
        'patient_id' => $patient2->patient->id,
    ]);
    $message = Message::factory()->create([
        'conversation_id' => $conversation->id,
        'sender_id' => $patient2->id,
    ]);
    $attachment = MessageAttachment::factory()->create([
        'message_id' => $message->id,
        'file_path' => 'attachments/secret.pdf',
        'original_name' => 'secret.pdf',
        'mime_type' => 'application/pdf',
    ]);

    Storage::disk('local')->put($attachment->file_path, 'private-file');

    // Patient1 cannot access Patient2's attachment
    $this->actingAs($patient1)
        ->get("/api/v1/conversation/attachments/{$attachment->id}")
        ->assertNotFound();

    // Non-existent ID also returns 404
    $this->actingAs($patient1)
        ->get('/api/v1/conversation/attachments/99999')
        ->assertNotFound();
});

test('mark read drives unread count to zero', function () {
    $patient = User::factory()->patient()->create();
    $conversation = Conversation::query()->create([
        'account_user_id' => $patient->id,
        'patient_id' => $patient->patient->id,
    ]);

    // Staff sends two messages
    $staff = User::factory()->create();
    Message::factory()->count(2)->create([
        'conversation_id' => $conversation->id,
        'sender_id' => $staff->id,
    ]);

    // Confirm unread count is 2
    $this->actingAs($patient)
        ->getJson('/api/v1/conversation')
        ->assertSuccessful()
        ->assertJsonPath('data.unread_count', 2);

    // Mark read
    $this->actingAs($patient)
        ->postJson('/api/v1/conversation/messages/read')
        ->assertSuccessful()
        ->assertJson(['marked_count' => 2]);

    // Unread count is now 0
    $this->actingAs($patient)
        ->getJson('/api/v1/conversation')
        ->assertSuccessful()
        ->assertJsonPath('data.unread_count', 0);
});

test('mark read is idempotent — second call returns zero', function () {
    $patient = User::factory()->patient()->create();
    $conversation = Conversation::query()->create([
        'account_user_id' => $patient->id,
        'patient_id' => $patient->patient->id,
    ]);

    $staff = User::factory()->create();
    Message::factory()->create([
        'conversation_id' => $conversation->id,
        'sender_id' => $staff->id,
    ]);

    $this->actingAs($patient)
        ->postJson('/api/v1/conversation/messages/read')
        ->assertSuccessful()
        ->assertJson(['marked_count' => 1]);

    $this->actingAs($patient)
        ->postJson('/api/v1/conversation/messages/read')
        ->assertSuccessful()
        ->assertJson(['marked_count' => 0]);
});

test('mark read does not mark the callers own messages', function () {
    $patient = User::factory()->patient()->create();
    $conversation = Conversation::query()->create([
        'account_user_id' => $patient->id,
        'patient_id' => $patient->patient->id,
    ]);

    // Patient sends a message
    Message::factory()->create([
        'conversation_id' => $conversation->id,
        'sender_id' => $patient->id,
    ]);

    // Staff sends a message
    $staff = User::factory()->create();
    Message::factory()->create([
        'conversation_id' => $conversation->id,
        'sender_id' => $staff->id,
    ]);

    $this->actingAs($patient)
        ->postJson('/api/v1/conversation/messages/read')
        ->assertSuccessful()
        ->assertJson(['marked_count' => 1]); // only the staff message
});

test('unlinked account can mark read', function () {
    $patient = User::factory()->patient()->create();
    // Remove the patient link to make it unlinked
    $patient->patient->update(['user_id' => null]);
    $patient->load('patient');

    $conversation = Conversation::query()->create([
        'account_user_id' => $patient->id,
        'patient_id' => null,
    ]);

    $staff = User::factory()->create();
    Message::factory()->create([
        'conversation_id' => $conversation->id,
        'sender_id' => $staff->id,
    ]);

    $this->actingAs($patient)
        ->postJson('/api/v1/conversation/messages/read')
        ->assertSuccessful()
        ->assertJson(['marked_count' => 1]);
});

test('account cannot mark another accounts conversation read', function () {
    $patient1 = User::factory()->patient()->create();
    $patient2 = User::factory()->patient()->create();
    $conversation = Conversation::query()->create([
        'account_user_id' => $patient2->id,
        'patient_id' => $patient2->patient->id,
    ]);

    $staff = User::factory()->create();
    Message::factory()->create([
        'conversation_id' => $conversation->id,
        'sender_id' => $staff->id,
    ]);

    // Patient1 marking read should only affect their own conversation
    $this->actingAs($patient1)
        ->postJson('/api/v1/conversation/messages/read')
        ->assertSuccessful()
        ->assertJson(['marked_count' => 0]);

    // Patient2's message is still unread
    $this->actingAs($patient2)
        ->getJson('/api/v1/conversation')
        ->assertSuccessful()
        ->assertJsonPath('data.unread_count', 1);
});

test('patient message notifies active staff only', function () {
    $patient = User::factory()->patient()->create();
    $conversation = Conversation::query()->create([
        'account_user_id' => $patient->id,
        'patient_id' => $patient->patient->id,
    ]);

    $activeStaff = User::factory()->staff()->create();
    $deactivatedStaff = User::factory()->staff()->create(['is_active' => false]);

    $this->actingAs($patient)
        ->postJson('/api/v1/conversation/messages', ['body' => 'Hello'])
        ->assertCreated();

    expect($activeStaff->fresh()->unreadNotifications)->toHaveCount(1);
    expect($deactivatedStaff->fresh()->unreadNotifications)->toHaveCount(0);
});

test('staff reply produces a patient notification', function () {
    $admin = User::factory()->admin()->create();
    $patient = User::factory()->patient()->create();
    $conversation = Conversation::query()->create([
        'account_user_id' => $patient->id,
        'patient_id' => $patient->patient->id,
    ]);

    $this->actingAs($admin);

    Livewire::test(ConversationChatPage::class)
        ->set('selectedConversationId', $conversation->id)
        ->set('replyBody', 'Staff reply')
        ->call('sendReply');

    expect($patient->fresh()->unreadNotifications)->toHaveCount(1);
});

test('conversation send is throttled at 10 per minute', function () {
    $patient = User::factory()->patient()->create();
    $conversation = Conversation::query()->create([
        'account_user_id' => $patient->id,
        'patient_id' => $patient->patient->id,
    ]);

    $this->actingAs($patient);

    // Send 10 messages successfully
    for ($i = 0; $i < 10; $i++) {
        $this->postJson('/api/v1/conversation/messages', ['body' => "Message $i"])
            ->assertCreated();
    }

    // 11th should be throttled
    $this->postJson('/api/v1/conversation/messages', ['body' => 'Too many'])
        ->assertStatus(429);
});
