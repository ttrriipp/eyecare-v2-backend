<?php

use App\Filament\Resources\Conversations\Pages\ConversationChatPage;
use App\Models\Conversation;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

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
