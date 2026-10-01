<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Party;
use Illuminate\Http\Request;

/**
 * Checking the playing device's token.
 *
 * The player has neither a session nor an account - it sits on the laptop wired
 * to the speakers and identifies itself with a pairing token. The same token
 * guards control of the queue and the stream of files from the host's disk, so
 * the logic lives in one place rather than in two copies.
 */
trait ChecksPlayerToken
{
    protected function assertToken(Request $request, Party $party): void
    {
        $token = $request->header('X-Player-Token') ?: $request->input('token');

        // hash_equals, because comparing a token character by character reveals
        // how many leading characters already match.
        abort_unless(
            is_string($token) && hash_equals((string) $party->player_token, $token),
            403,
            'Nieprawidłowy token urządzenia.'
        );
    }
}
