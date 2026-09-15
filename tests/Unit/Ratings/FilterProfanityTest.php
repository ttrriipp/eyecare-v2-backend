<?php

use App\Actions\Ratings\FilterProfanity;

test('masks English and Filipino profanity regardless of case', function () {
    $comment = (new FilterProfanity)->handle('This is SHIT and PUTANG INA!');

    expect($comment)->toBe('This is **** and ****!');
});

test('does not mask profanity fragments inside other words', function () {
    $comment = (new FilterProfanity)->handle('The assessment was classic and thoughtful.');

    expect($comment)->toBe('The assessment was classic and thoughtful.');
});
