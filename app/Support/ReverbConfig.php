<?php

namespace App\Support;

/**
 * The live-connection settings handed to the browser.
 *
 * IMPORTANT: these are NOT the values the server itself uses.
 *
 *   server  -> Reverb   localhost:8080       (config/broadcasting.php)
 *   browser -> Reverb   the public url:443   (this class)
 *
 * Behind a tunnel the public address goes through Cloudflare. Were the server to
 * broadcast events to that address, every hype would travel out to the internet
 * and back to the same machine - slow and unreliable. The browser, in turn, has
 * no way of reaching the host's "localhost".
 */
class ReverbConfig
{
    public static function forBrowser(): array
    {
        return [
            'key' => config('broadcasting.connections.reverb.key'),
            // The public address when one is set (a tunnel, a domain).
            // Without it we fall back to whatever the server listens on.
            'host' => env('REVERB_PUBLIC_HOST')
                ?: config('broadcasting.connections.reverb.options.host'),
            'port' => (int) (env('REVERB_PUBLIC_PORT')
                ?: config('broadcasting.connections.reverb.options.port', 8080)),
            'scheme' => env('REVERB_PUBLIC_SCHEME')
                ?: config('broadcasting.connections.reverb.options.scheme', 'http'),
        ];
    }
}
