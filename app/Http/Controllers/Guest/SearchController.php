<?php

namespace App\Http\Controllers\Guest;

use App\Http\Controllers\Controller;
use App\Models\Party;
use App\Services\MusicSearch;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    public function __construct(private MusicSearch $search) {}

    /**
     * Podpowiedzi w trakcie pisania. Katalog + cache, zero jednostek limitu.
     */
    public function suggest(Request $request, Party $party)
    {
        $query = (string) $request->query('q', '');

        return response()->json($this->search->suggest($query, $party, 12));
    }

    /**
     * A full search across the whole of YouTube - a guest's deliberate click.
     * It costs 100 units, so we protect it with a rate limit.
     */
    public function youtube(Request $request, Party $party)
    {
        $guest = $request->attributes->get('guest');

        if (! $guest || $guest->is_banned) {
            return response()->json(['error' => 'Brak dostepu.'], 403);
        }

        // One guest must not burn through the party's budget by clicking away.
        $key = "yt_search:{$guest->id}";

        if (cache()->get($key, 0) >= 3) {
            return response()->json([
                'error' => 'Odczekaj chwile przed kolejnym szukaniem na YouTube.',
            ], 429);
        }

        cache()->put($key, cache()->get($key, 0) + 1, now()->addMinute());

        return response()->json(
            $this->search->searchYouTube((string) $request->query('q', ''), $party, 12)
        );
    }
}
