<?php

test('guests are redirected from the dashboard to the login screen', function () {
    $this->get('/')->assertRedirect(route('login'));
});
