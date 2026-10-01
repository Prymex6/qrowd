<?php

namespace App\Http\Controllers\Host;

use App\Http\Controllers\Controller;
use App\Models\CatalogTrack;
use App\Services\LocalLibrary;
use Illuminate\Http\Request;

/**
 * The listing of music from the host's laptop.
 *
 * THE MOST IMPORTANT THING: the server NEVER sees or reads the files.
 *
 * The folder is chosen by the browser on the laptop wired to the speakers; that
 * browser reads the names and durations out of it and sends here THE LISTING
 * ALONE - title, artist, duration, relative path. No sound travels anywhere: the
 * player opens the file straight from the disk, through the folder handle the
 * user granted it.
 *
 * That is what lets QRowd sit on any hosting while the music stays on the host's
 * computer - no uploading, no transfer, and no question of who is holding
 * somebody else's files.
 */
class MusicFolderController extends Controller
{
    /** Ile pozycji przyjmujemy w jednej paczce - reszta doleci kolejnymi. */
    private const PER_BATCH = 500;

    /**
     * Takes one piece of the listing from the browser.
     *
     * The player sends the library in batches, because with a few thousand files
     * a single request would be too large and would show no progress.
     */
    public function index(Request $request)
    {
        $data = $request->validate([
            'folder' => ['required', 'string', 'max:255'],
            'first' => ['nullable', 'boolean'],
            'tracks' => ['required', 'array', 'max:'.self::PER_BATCH],
            'tracks.*.path' => ['required', 'string', 'max:500'],
            'tracks.*.title' => ['required', 'string', 'max:250'],
            'tracks.*.artist' => ['nullable', 'string', 'max:250'],
            'tracks.*.duration' => ['required', 'integer', 'min:1', 'max:65535'],
        ]);

        $host = $request->user();

        // The first batch starts the library afresh. Walking the folder is
        // exhaustive, so appending to the old listing would leave behind tracks
        // for files the host has since deleted.
        if ($request->boolean('first')) {
            CatalogTrack::where('user_id', $host->id)->whereNotNull('local_path')->delete();

            $host->forceFill(['music_folder' => $data['folder']])->save();
        }

        $added = 0;

        foreach ($data['tracks'] as $u) {
            $explicit = CatalogTrack::looksExplicit($u['title'], $u['artist'] ?? null);

            CatalogTrack::updateOrCreate(
                ['youtube_id' => LocalLibrary::idFor($u['path'])],
                [
                    'user_id' => $host->id,
                    'local_path' => $u['path'],
                    'title' => $u['title'],
                    'artist' => $u['artist'] ?? null,
                    'title_raw' => trim(($u['artist'] ?? '').' - '.$u['title'], ' -'),
                    'duration_seconds' => $u['duration'],
                    'is_embeddable' => true,
                    'is_explicit' => $explicit,
                    'is_wedding_safe' => ! $explicit,
                    // Wlasny plik to czysty dzwiek, bez mowionego intro z teledysku.
                    'is_topic' => true,
                    'source' => 'manual',
                    'checked_at' => now(),
                ]
            );

            $added++;
        }

        return response()->json([
            'accepted' => $added,
            'inLibrary' => CatalogTrack::where('user_id', $host->id)
                ->whereNotNull('local_path')->count(),
        ]);
    }

    /** Forgets the library - the files on the host's disk are left untouched. */
    public function forget(Request $request)
    {
        $host = $request->user();

        CatalogTrack::where('user_id', $host->id)->whereNotNull('local_path')->delete();
        $host->forceFill(['music_folder' => null])->save();

        return response()->json(['ok' => true]);
    }
}
