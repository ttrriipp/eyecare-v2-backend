<?php

use App\Filament\Resources\Conversations\Pages\ListConversations;
use App\Filament\Resources\Conversations\Pages\ViewConversation;
use App\Filament\Resources\Conversations\RelationManagers\MessagesRelationManager;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('staff and admin can list conversations', function (string $role) {
    $user = User::factory()->{$role}()->create();
    $conversations = Conversation::factory()->count(3)->create();

    $this->actingAs($user);

    Livewire::test(ListConversations::class)
        ->assertCanSeeTableRecords($conversations);
})->with(['staff', 'admin']);

test('conversations table shows customer name and subject', function () {
    $staff = User::factory()->staff()->create();
    $conversation = Conversation::factory()->create(['subject' => 'Test subject']);

    $this->actingAs($staff);

    Livewire::test(ListConversations::class)
        ->assertCanSeeTableRecords([$conversation]);
});

test('conversations can be filtered by appointment context', function () {
    $staff = User::factory()->staff()->create();

    $withAppointment = Conversation::factory()->create([
        'appointment_id' => \App\Models\Appointment::factory()->create()->id,
    ]);
    $withoutAppointment = Conversation::factory()->create(['appointment_id' => null]);

    $this->actingAs($staff);

    Livewire::test(ListConversations::class)
        ->filterTable('has_appointment')
        ->assertCanSeeTableRecords([$withAppointment])
        ->assertCanNotSeeTableRecords([$withoutAppointment]);
});

test('conversations can be filtered by order context', function () {
    $staff = User::factory()->staff()->create();

    $withOrder = Conversation::factory()->create([
        'order_id' => \App\Models\Order::factory()->create()->id,
    ]);
    $withoutOrder = Conversation::factory()->create(['order_id' => null]);

    $this->actingAs($staff);

    Livewire::test(ListConversations::class)
        ->filterTable('has_order')
        ->assertCanSeeTableRecords([$withOrder])
        ->assertCanNotSeeTableRecords([$withoutOrder]);
});

test('staff can view a conversation', function () {
    $staff = User::factory()->staff()->create();
    $conversation = Conversation::factory()->create();

    $this->actingAs($staff);

    Livewire::test(ViewConversation::class, ['record' => $conversation->getRouteKey()])
        ->assertSuccessful();
});

test('staff can see messages in the relation manager', function () {
    $staff = User::factory()->staff()->create();
    $conversation = Conversation::factory()->create();
    $messages = Message::factory()->count(2)->create([
        'conversation_id' => $conversation->id,
        'sender_id' => $conversation->customer_id,
    ]);

    $this->actingAs($staff);

    Livewire::test(MessagesRelationManager::class, [
        'ownerRecord' => $conversation,
        'pageClass' => ViewConversation::class,
    ])
        ->assertCanSeeTableRecords($messages);
});

test('staff can reply to a conversation', function () {
    $staff = User::factory()->staff()->create();
    $conversation = Conversation::factory()->create();

    $this->actingAs($staff);

    Livewire::test(MessagesRelationManager::class, [
        'ownerRecord' => $conversation,
        'pageClass' => ViewConversation::class,
    ])
        ->callAction(TestAction::make('reply')->table(), data: ['body' => 'Hello from staff.'])
        ->assertNotified();

    $this->assertDatabaseHas(Message::class, [
        'conversation_id' => $conversation->id,
        'sender_id' => $staff->id,
        'body' => 'Hello from staff.',
    ]);
});

test('reply action requires a body', function () {
    $staff = User::factory()->staff()->create();
    $conversation = Conversation::factory()->create();

    $this->actingAs($staff);

    Livewire::test(MessagesRelationManager::class, [
        'ownerRecord' => $conversation,
        'pageClass' => ViewConversation::class,
    ])
        ->callAction(TestAction::make('reply')->table(), data: ['body' => ''])
        ->assertHasActionErrors(['body' => 'required']);
});
