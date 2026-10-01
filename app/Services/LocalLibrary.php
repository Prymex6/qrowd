<?php

namespace App\Services;

/**
 * The host's on-disk music library.
 *
 * The server never reads the files. The browser running on the laptop wired
 * to the speakers picks the folder, scans it and uploads a plain listing --
 * so all that is left here is how a track gets its identifier, shared by
 * both sides.
 *
 * This exists for one reason: a wedding venue can turn out to have no
 * internet, and then the whole app is useless despite a full queue.
 */
class LocalLibrary
{
    /**
     * Identifier for a track that lives on disk.
     *
     * The youtube_id column is required and unique, and every existing rule
     * leans on it -- repeat blocking, merging duplicates in the queue, dedup.
     * Rather than loosening that column and taking a risk in a dozen places,
     * a disk track gets its own stable id: "L" plus a hash of the path.
     * It fits in 20 characters and cannot be mistaken for a real YouTube id,
     * which is 11 characters long.
     *
     * The browser computes the same value -- see `trackId` in the player.
     * If the two ever disagreed, the player would not find the file for
     * a queue entry.
     */
    public static function idFor(string $relativePath): string
    {
        return 'L'.substr(hash('sha256', str_replace('\\', '/', $relativePath)), 0, 19);
    }
}
