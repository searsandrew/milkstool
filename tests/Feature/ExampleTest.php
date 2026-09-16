<?php

it('serves a public JSON liveness endpoint', function () {
    $this->get('/up')->assertOk()->assertExactJson(['status' => 'ok']);
});

it('returns 404 JSON for browser pages', function (string $path) {
    $this->get($path)->assertNotFound()->assertHeader('content-type', 'application/json');
})->with(['/', '/login', '/register', '/sanctum/csrf-cookie']);
