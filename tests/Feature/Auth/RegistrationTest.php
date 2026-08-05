<?php

test('public registration screen is unavailable', function () {
    $response = $this->get('/register');

    $response->assertNotFound();
});

test('public registration endpoint is unavailable', function () {
    $response = $this->post('/register', [
        'name' => 'John Doe',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $response->assertNotFound();
    $this->assertGuest();
});
