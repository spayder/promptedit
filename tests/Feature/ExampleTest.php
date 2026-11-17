<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

it('shows guests the landing page', function () {
    $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Welcome'));
});

it('sends signed-in people straight to the generate page', function () {
    $this->actingAs(User::factory()->create())->get('/')->assertRedirect('/generate');
});
