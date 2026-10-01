<?php

namespace Tests;

use App\Http\Middleware\ResolveGuest;
use App\Models\Guest;
use App\Models\Party;
use Illuminate\Support\Str;

/**
 * A shared base for the tests that touch a party.
 *
 * A guest has no account - their identity is a cookie holding a device id. To
 * test their path, that cookie has to be supplied.
 */
abstract class PartyTestCase extends TestCase
{
    protected function guestFor(Party $party, array $attrs = []): array
    {
        $deviceId = Str::random(40);

        $guest = Guest::factory()->create(array_merge([
            'party_id' => $party->id,
            'device_hash' => ResolveGuest::hash($deviceId),
        ], $attrs));

        return [$guest, $deviceId];
    }

    /**
     * Returns a test request carrying the device cookie.
     *
     * We use withCookie(), not withUnencryptedCookie(): the application passes
     * cookies through EncryptCookies, so a raw value would be rejected when it
     * failed to decrypt and the guest would go unrecognised.
     */
    protected function asGuest(string $deviceId): static
    {
        // withCredentials() is needed because Laravel's test harness does not
        // attach cookies to JSON requests until asked. In a browser, fetch() on
        // the same origin sends them by itself.
        return $this->withCookie(ResolveGuest::COOKIE, $deviceId)->withCredentials();
    }

    protected function trackPayload(array $overrides = []): array
    {
        return array_merge([
            'youtube_id' => Str::random(11),
            'title' => 'Testowy kawalek',
            'artist' => 'Testowy Wykonawca',
            'duration_seconds' => 200,
        ], $overrides);
    }
}
