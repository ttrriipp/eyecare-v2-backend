<?php

test('conversation table no longer references legacy context columns', function () {
    $table = file_get_contents(app_path('Filament/Resources/Conversations/Tables/ConversationsTable.php'));

    expect($table)->toContain("TextColumn::make('patient.first_name')")
        ->and($table)->not->toContain('customer')
        ->and($table)->not->toContain('subject')
        ->and($table)->not->toContain('appointment_id')
        ->and($table)->not->toContain('order_id')
        ->and($table)->not->toContain('order.');
});

test('patient conversation request does not accept legacy order context', function () {
    $request = file_get_contents(app_path('Http/Requests/Api/StoreConversationRequest.php'));

    expect($request)->not->toContain("'order_id'")
        ->and($request)->not->toContain('"order_id"')
        ->and($request)->not->toContain("'subject'")
        ->and($request)->not->toContain("'appointment_id'");
});
