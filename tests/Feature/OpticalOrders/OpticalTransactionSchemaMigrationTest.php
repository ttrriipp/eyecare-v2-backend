<?php

/**
 * Tests for fulfillment schema migration.
 */

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

test('job_orders has fulfillment_mode column', function () {
    expect(Schema::hasColumn('job_orders', 'fulfillment_mode'))->toBeTrue();
});

test('job_orders has uses_external_supplier column', function () {
    expect(Schema::hasColumn('job_orders', 'uses_external_supplier'))->toBeTrue();
});
