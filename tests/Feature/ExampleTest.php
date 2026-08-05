<?php

test('home redirects guests to login', function () {
    $response = $this->get(route('home'));

    $response->assertRedirect('/login');
});
