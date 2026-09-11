<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Session\Middleware\AuthenticateSession;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Binds each session to the user's current password hash, so changing a
        // password (or logging out other devices) invalidates sibling sessions.
        $middleware->web(append: [
            AuthenticateSession::class,
        ]);

        // The public capture form is embedded in an iframe on other people's
        // sites, where the session cookie is a third-party cookie and browsers
        // increasingly refuse to send it — so a CSRF token could not be checked
        // even when one was issued. What stands in for it: the route is a
        // random 32-character token, the only thing it can do is create a lead
        // owned by whoever the form names, and it is rate limited, honeypotted
        // and timed. Nothing else in the application is exempt.
        $middleware->validateCsrfTokens(except: [
            'f/*',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
