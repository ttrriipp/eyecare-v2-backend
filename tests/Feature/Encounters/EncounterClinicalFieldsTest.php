<?php

use App\Models\Encounter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

test('assessment column exists and is nullable', function () {
    $encounter = Encounter::factory()->create(['assessment' => null]);

    expect($encounter->fresh()->assessment)->toBeNull();
});

test('supporting_test_results column exists and is nullable', function () {
    $encounter = Encounter::factory()->create(['supporting_test_results' => null]);

    expect($encounter->fresh()->supporting_test_results)->toBeNull();
});

test('assessment is encrypted at rest', function () {
    $encounter = Encounter::factory()->create([
        'assessment' => 'Mild myopia, no signs of glaucoma',
    ]);

    $raw = DB::table('encounters')->where('id', $encounter->id)->first();

    expect($raw->assessment)->not->toBe('Mild myopia, no signs of glaucoma')
        ->and($raw->assessment)->not->toBeNull();

    $fresh = Encounter::find($encounter->id);
    expect($fresh->assessment)->toBe('Mild myopia, no signs of glaucoma');
});

test('supporting_test_results is encrypted at rest', function () {
    $encounter = Encounter::factory()->create([
        'supporting_test_results' => 'Tonometry: 15mmHg OD, 16mmHg OS',
    ]);

    $raw = DB::table('encounters')->where('id', $encounter->id)->first();

    expect($raw->supporting_test_results)->not->toBe('Tonometry: 15mmHg OD, 16mmHg OS')
        ->and($raw->supporting_test_results)->not->toBeNull();

    $fresh = Encounter::find($encounter->id);
    expect($fresh->supporting_test_results)->toBe('Tonometry: 15mmHg OD, 16mmHg OS');
});

test('factory supports complete clinical draft with assessment and supporting results', function () {
    $encounter = Encounter::factory()->inProgress()->create([
        'chief_complaint' => 'Blurred vision',
        'findings' => 'Normal anterior segment',
        'assessment' => 'Myopia progression',
        'supporting_test_results' => 'Refraction: -2.50 OD',
        'plan' => 'Update prescription',
    ]);

    expect($encounter->assessment)->toBe('Myopia progression')
        ->and($encounter->supporting_test_results)->toBe('Refraction: -2.50 OD')
        ->and($encounter->chief_complaint)->toBe('Blurred vision')
        ->and($encounter->findings)->toBe('Normal anterior segment')
        ->and($encounter->plan)->toBe('Update prescription');
});

test('factory supports partial clinical draft without assessment', function () {
    $encounter = Encounter::factory()->inProgress()->create([
        'chief_complaint' => 'Eye strain',
        'findings' => null,
        'assessment' => null,
        'supporting_test_results' => null,
        'plan' => null,
    ]);

    expect($encounter->chief_complaint)->toBe('Eye strain')
        ->and($encounter->findings)->toBeNull()
        ->and($encounter->assessment)->toBeNull()
        ->and($encounter->supporting_test_results)->toBeNull()
        ->and($encounter->plan)->toBeNull();
});

test('existing completed encounters remain readable without assessment', function () {
    $encounter = Encounter::factory()->completed()->create([
        'findings' => 'Historical findings',
        'remarks' => 'Historical remarks',
        'assessment' => null,
        'supporting_test_results' => null,
    ]);

    $fresh = Encounter::find($encounter->id);

    expect($fresh->findings)->toBe('Historical findings')
        ->and($fresh->remarks)->toBe('Historical remarks')
        ->and($fresh->assessment)->toBeNull()
        ->and($fresh->supporting_test_results)->toBeNull()
        ->and($fresh->status->value)->toBe('completed');
});
