<?php

test('privacy notice is publicly available', function (): void {
    $this->get('/privacy')
        ->assertSuccessful()
        ->assertViewIs('legal.privacy')
        ->assertSee('Privacy notice')
        ->assertSee('patient mobile app')
        ->assertSee('synthetic test data only')
        ->assertSee('/terms')
        ->assertDontSee('staff-only');
});

test('terms of use are publicly available', function (): void {
    $this->get('/terms')
        ->assertSuccessful()
        ->assertViewIs('legal.terms')
        ->assertSee('Terms of use')
        ->assertSee('patient mobile app')
        ->assertSee('real patient or health information')
        ->assertSee('/privacy')
        ->assertDontSee('staff-only');
});
