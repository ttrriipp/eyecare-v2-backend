<?php

test('patient conversation request does not accept legacy order context', function () {
    $request = file_get_contents(app_path('Http/Requests/Api/StoreConversationRequest.php'));

    expect($request)->not->toContain("'order_id'")
        ->and($request)->not->toContain('"order_id"')
        ->and($request)->not->toContain("'subject'")
        ->and($request)->not->toContain("'appointment_id'");
});
