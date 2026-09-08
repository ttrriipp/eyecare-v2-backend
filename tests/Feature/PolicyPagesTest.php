<?php

test('privacy notice is publicly available', function (): void {
    $this->get('/privacy')
        ->assertSuccessful()
        ->assertViewIs('legal.privacy')
        ->assertSee('Privacy notice')
        ->assertSee('temporary staff-only academic demonstration')
        ->assertSee('/terms');
});

test('terms of use are publicly available', function (): void {
    $this->get('/terms')
        ->assertSuccessful()
        ->assertViewIs('legal.terms')
        ->assertSee('Terms of use')
        ->assertSee('synthetic clinic records')
        ->assertSee('/privacy');
});
