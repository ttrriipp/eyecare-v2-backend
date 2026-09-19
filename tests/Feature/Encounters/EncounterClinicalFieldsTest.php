<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

test('removed clinical columns no longer exist on encounters', function () {
    foreach (['plan', 'assessment', 'supporting_test_results'] as $column) {
        expect(Schema::hasColumn('encounters', $column))->toBeFalse();
    }
});
