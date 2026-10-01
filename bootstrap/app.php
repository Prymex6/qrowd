<?php

use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\ResolveGuest;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        // Behind a tunnel or a load balancer the traffic reaches PHP over HTTP
        // even though the guest connects over HTTPS. Without trusting the proxy
        // headers Laravel builds links and QR codes with "http://" - and then
        // neither the PWA nor camera access for scanning works on a phone.
        $middleware->trustProxies(at: '*');

        // The alias is 'party.guest', not 'guest': Laravel already ships a
        // 'guest' alias (RedirectIfAuthenticated), and taking that name over
        // does not raise an error - the framework's own middleware simply runs
        // instead of ours, every guest stays unrecognised and every guest route
        // answers with a redirect or a 403.
        $middleware->alias([
            'party.guest' => ResolveGuest::class,
            'admin' => EnsureAdmin::class,
        ]);

        // Laravel looks for a route named "login" when it turns an
        // unauthenticated visitor away. Ours is named that, and without this
        // line a guest used to get a 500 instead of the sign-in form.
        $middleware->redirectGuestsTo(fn () => route('login'));

        $middleware->validateCsrfTokens(except: [
            // The payment notification arrives from the operator's server
            // rather than from a browser - there is nowhere for it to get a
            // token. We check the signature computed from the shared password
            // instead.
            'platnosc/hotpay',

            // The playing device authenticates with its own pairing token
            // (the X-Player-Token header) rather than with a session cookie.
            // CSRF protection defends against cookies being abused, so here it
            // only gets in the way.
            'api/player/*',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
