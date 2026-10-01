<?php

namespace App\Support;

use App\Models\Party;
use Illuminate\Http\Request;

/**
 * Whether the device now joining is somebody in the room.
 *
 * Guests arrive from phones - they scanned the QR code off the wall or off a
 * card on the table. Whoever joins from a computer is almost always the host
 * checking how it looks.
 *
 * The distinction has one concrete effect: such a device does not enter the
 * denominator of the vote to skip a track. It may still vote - if the detection
 * gets it wrong, a real guest with an unusual browser still has their button.
 */
class GuestDevice
{
    /**
     * Wzorce telefonow i tabletow. Celowo szeroka lista - falszywe
     * "to telefon" jest nieszkodliwe, falszywe "to komputer" wycina
     * kogos z liczenia sali.
     */
    private const MOBILE = [
        'Android', 'iPhone', 'iPad', 'iPod', 'Mobile', 'Opera Mini',
        'webOS', 'BlackBerry', 'BB10', 'Windows Phone', 'IEMobile',
        'Silk', 'Kindle', 'HarmonyOS', 'Tablet',
    ];

    public static function countsInRoom(Request $request, Party $party): bool
    {
        // A signed-in party owner is not a guest, even when they happen to be
        // looking at the guest view on a phone.
        if ($request->user() && $request->user()->id === $party->user_id) {
            return false;
        }

        return self::isMobile((string) $request->userAgent());
    }

    public static function isMobile(string $userAgent): bool
    {
        if ($userAgent === '') {
            // A missing header usually means a script or a test, not a person dancing.
            return false;
        }

        foreach (self::MOBILE as $pattern) {
            if (stripos($userAgent, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }
}
