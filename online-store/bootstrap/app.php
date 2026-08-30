<?php

declare(strict_types=1);

use App\Http\Middleware\EnsureAccountIsActive;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\AuthenticateSession;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        /*
         * AuthenticateSession is what makes ChangePassword's
         * Auth::logoutOtherDevices() actually do something. Without it that
         * call rewrites the password hash and nothing checks any other
         * session against it, so every other browser stays signed in — which
         * is the opposite of what requiring current_password is for.
         *
         * Filament's panel already had this (AdminPanelProvider's own
         * ->middleware() stack, which Filament scaffolds); the storefront's
         * `web` group did not, so the protection existed for staff and not
         * for customers.
         *
         * EnsureAccountIsActive is the same class of problem one layer over:
         * a session outlives the row it authenticated against, so
         * deactivating or deleting a customer has to end their access on the
         * next request rather than at a next login they will never make.
         * canAccessPanel() already states that reasoning for the panel.
         */
        $middleware->web(append: [
            AuthenticateSession::class,
            EnsureAccountIsActive::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
