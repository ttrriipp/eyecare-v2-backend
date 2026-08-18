<?php

use App\Models\MessageAttachment;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('formatted file size shows kilobytes under 1000 kb', function () {
    $attachment = MessageAttachment::factory()->make(['file_size' => 573_500]);

    expect($attachment->formatted_file_size)->toBe('560.1 KB');
});

test('formatted file size switches to megabytes at 1000 kb and above', function () {
    $attachment = MessageAttachment::factory()->make(['file_size' => 2_645_686]);

    expect($attachment->formatted_file_size)->toBe('2.5 MB');
});
