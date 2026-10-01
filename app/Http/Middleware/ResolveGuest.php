<?php

namespace App\Http\Middleware;

use App\Models\Guest;
use App\Models\Party;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Recognises a guest by the device id kept in a cookie.
 *
 * A guest has no account, no password and signs up for nothing - they scan the
 * QR code and they are in. Their whole identity is a signed cookie holding a
 * random id, which then serves the submission limits and the bans.
 */
class ResolveGuest
{
    public const COOKIE = 'qrowd_device';

    public function handle(Request $request, Closure $next): Response
    {
        $party = $request->route('party');

        if ($party instanceof Party) {
            $deviceId = $request->cookie(self::COOKIE);

            if ($deviceId) {
                $guest = Guest::where('party_id', $party->id)
                    ->where('device_hash', self::hash($deviceId))
                    ->first();

                $request->attributes->set('guest', $guest);

                if ($guest && (! $guest->last_seen_at || $guest->last_seen_at->lt(now()->subMinute()))) {
                    $guest->forceFill(['last_seen_at' => now()])->saveQuietly();
                }
            }
        }

        return $next($request);
    }

    public static function hash(string $deviceId): string
    {
        return hash('sha256', $deviceId);
    }
}
