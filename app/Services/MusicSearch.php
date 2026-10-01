<?php

namespace App\Services;

use App\Models\ApiUsage;
use App\Models\CatalogTrack;
use App\Models\Party;
use App\Models\SearchCache;
use App\Services\YouTube\QuotaGuard;
use App\Services\YouTube\YouTubeClient;
use App\Support\PartySettings;

/**
 * Searching for tracks, in two modes.
 *
 * SUGGESTIONS (suggest) - they fire while a guest types in the field.
 *   The local catalogue plus the shared cache. Zero quota units, under 20 ms.
 *
 * A FULL SEARCH (searchYouTube) - only on a deliberate click of
 *   "Szukaj na YouTube". It reaches the whole of YouTube and costs 100 units.
 *
 * That split is the entire trick. A guest has access to everything on YouTube,
 * but the quota is spent solely on the queries that genuinely need it - not on
 * every keystroke.
 *
 * Every result from YouTube joins the catalogue, so the database grows by
 * itself and next time the same question is already free.
 */
class MusicSearch
{
    private const CACHE_DAYS = 30;

    public function __construct(
        private YouTubeClient $youtube,
        private QuotaGuard $quota,
    ) {}

    public static function make(): self
    {
        return new self(YouTubeClient::make(), QuotaGuard::fromConfig());
    }

    /**
     * Suggestions while typing. It NEVER touches YouTube.
     *
     * @return array{query:string, layer:string, tracks:array, youtube_available:bool}
     */
    public function suggest(string $query, ?Party $party = null, int $limit = 12): array
    {
        $query = $this->clean($query);
        $settings = $party?->settings() ?? PartySettings::make(null);

        if (mb_strlen($query) < 2) {
            return $this->result($query, 'none', [], $party, $settings);
        }

        $source = (string) $settings->get('music_source', 'youtube');
        $tracks = $this->fromCatalog($query, $limit * 2, $source, $party?->user_id);
        $layer = $source === 'disk' ? 'disk' : 'catalog';

        // The cache holds YouTube results alone, so at a party with no internet
        // it is of no use - a guest would see tracks that cannot be played.
        if ($source !== 'disk' && count($tracks) < $limit) {
            if ($cached = $this->fromCache($query)) {
                $tracks = $this->mergeUnique($tracks, $cached);
                $layer = 'catalog+cache';
            }
        }

        return $this->result($query, $layer, $this->applyFilters($tracks, $party, $settings, $limit), $party, $settings);
    }

    /**
     * A full search across the whole of YouTube. It costs 100 quota units, so we
     * call it only on a guest's deliberate request.
     */
    public function searchYouTube(string $query, ?Party $party = null, int $limit = 12): array
    {
        $query = $this->clean($query);
        $settings = $party?->settings() ?? PartySettings::make(null);

        if (mb_strlen($query) < 2) {
            return $this->result($query, 'none', [], $party, $settings);
        }

        // A party with no internet: reaching for YouTube makes no sense, and the
        // guest would get results the player cannot play.
        if ((string) $settings->get('music_source', 'youtube') === 'disk') {
            $r = $this->suggest($query, $party, $limit);
            $r['layer'] = 'disk';
            $r['reason'] = 'Ta impreza gra z biblioteki organizatora, bez internetu.';

            return $r;
        }

        // The host can shut a party inside the catalogue - at a company dinner, say.
        if ($settings->bool('catalog_only')) {
            $r = $this->suggest($query, $party, $limit);
            $r['layer'] = 'catalog_only';
            $r['reason'] = 'Organizator ograniczył wybór do zweryfikowanej bazy utworów.';

            return $r;
        }

        $source = (string) $settings->get('music_source', 'youtube');

        // The result may already sit in the cache from another party - free then.
        if ($cached = $this->fromCache($query)) {
            $merged = $this->mergeUnique($this->fromCatalog($query, $limit, $source, $party?->user_id), $cached);

            return $this->result($query, 'cache', $this->applyFilters($merged, $party, $settings, $limit), $party, $settings);
        }

        if (! $this->quota->canSpend('search')) {
            $r = $this->suggest($query, $party, $limit);
            $r['layer'] = 'quota_exhausted';
            $r['reason'] = 'Dzienny limit wyszukiwan YouTube wyczerpany. Szukamy w katalogu.';

            return $r;
        }

        if ($party && ! $this->partyWithinBudget($party, $settings)) {
            $r = $this->suggest($query, $party, $limit);
            $r['layer'] = 'party_budget';
            $r['reason'] = 'Ta impreza wykorzystała swój budżet wyszukiwań YouTube.';

            return $r;
        }

        $fresh = $this->youtube->search($query, $limit, $party?->id);

        if ($fresh !== []) {
            $this->storeCache($query, $fresh);
            $this->growCatalog($fresh);
        }

        $merged = $this->mergeUnique($this->fromCatalog($query, $limit, $source, $party?->user_id), $fresh);

        return $this->result($query, 'youtube', $this->applyFilters($merged, $party, $settings, $limit), $party, $settings);
    }

    // ------------------------------------------------------------ warstwy

    private function fromCatalog(string $query, int $limit, string $source, ?int $hostId = null): array
    {
        $rows = CatalogTrack::query()->playable()->fromSource($source, $hostId)
            ->search($query)->limit($limit)->get();

        // FULLTEXT gubi krotkie i czesciowe hasla - wtedy dobieramy po fragmencie.
        if ($rows->count() < 5) {
            $extra = CatalogTrack::query()->playable()->fromSource($source, $hostId)->searchLike($query)
                ->orderByDesc('play_count')->limit($limit)->get();

            $rows = $rows->concat($extra)->unique('youtube_id');
        }

        return $rows->map(fn (CatalogTrack $t) => [
            'youtube_id' => $t->youtube_id,
            'title' => $t->title,
            'artist' => $t->artist,
            // The thumbnail and the original title - the guest needs something to
            // confirm that this is exactly the song they meant.
            'thumbnail' => $t->thumbnail(),
            'title_raw' => $t->title_raw,
            'cleanAudio' => (bool) $t->is_topic,
            'duration_seconds' => $t->duration_seconds,
            'genre' => $t->genre,
            'is_explicit' => (bool) $t->is_explicit,
            'is_embeddable' => (bool) $t->is_embeddable,
            'catalog_track_id' => $t->id,
            // This is how the player knows to play from disk, not from YouTube.
            'local_path' => $t->local_path,
            'fromDisk' => $t->local_path !== null,
            'source' => 'catalog',
        ])->values()->all();
    }

    private function fromCache(string $query): array
    {
        $row = SearchCache::where('query_hash', SearchCache::hashFor($query))
            ->where('expires_at', '>', now())
            ->first();

        if (! $row) {
            return [];
        }

        $row->increment('hits');

        return $row->results;
    }

    private function storeCache(string $query, array $tracks): void
    {
        SearchCache::updateOrCreate(
            ['query_hash' => SearchCache::hashFor($query)],
            [
                'query' => mb_substr($query, 0, 200),
                'results' => $tracks,
                'expires_at' => now()->addDays(self::CACHE_DAYS),
            ]
        );
    }

    /** Every track from YouTube joins the catalogue - the database grows by itself. */
    private function growCatalog(array $tracks): void
    {
        foreach ($tracks as $track) {
            if (empty($track['youtube_id']) || empty($track['title'])) {
                continue;
            }

            CatalogTrack::updateOrCreate(
                ['youtube_id' => $track['youtube_id']],
                [
                    'title' => mb_substr($track['title'], 0, 250),
                    'artist' => ! empty($track['artist']) ? mb_substr($track['artist'], 0, 250) : null,
                    'duration_seconds' => min(65535, (int) ($track['duration_seconds'] ?? 0)),
                    'is_embeddable' => (bool) ($track['is_embeddable'] ?? true),
                    'source' => 'youtube',
                    'checked_at' => now(),
                ]
            );
        }
    }

    /** One party must not burn through the quota for all the others. */
    private function partyWithinBudget(Party $party, PartySettings $settings): bool
    {
        $budget = $settings->int('youtube_search_budget');

        if ($budget <= 0) {
            return true;
        }

        $used = ApiUsage::where('party_id', $party->id)
            ->where('operation', 'search')
            ->whereDate('quota_date', $this->quota->quotaDate())
            ->count();

        return $used < $budget;
    }

    // ------------------------------------------------------------ filtry

    private function applyFilters(array $tracks, ?Party $party, PartySettings $settings, int $limit): array
    {
        $blockedArtists = [];
        $blockedTracks = [];
        $blockedKeywords = array_map('mb_strtolower', (array) $settings->get('blocked_keywords', []));

        if ($party) {
            foreach ($party->blocks as $block) {
                if ($block->type === 'artist') {
                    $blockedArtists[] = mb_strtolower($block->value);
                }
                if ($block->type === 'track') {
                    $blockedTracks[] = $block->value;
                }
                if ($block->type === 'keyword') {
                    $blockedKeywords[] = mb_strtolower($block->value);
                }
            }
        }

        $maxSeconds = $settings->int('max_video_seconds');
        $minSeconds = $settings->int('min_track_seconds');

        return collect($tracks)
            ->filter(function (array $t) use ($settings, $blockedArtists, $blockedTracks, $blockedKeywords, $maxSeconds, $minSeconds) {
                if (($t['is_embeddable'] ?? true) === false) {
                    return false;
                }
                if (in_array($t['youtube_id'], $blockedTracks, true)) {
                    return false;
                }
                if ($settings->bool('filter_explicit') && ($t['is_explicit'] ?? false)) {
                    return false;
                }

                $duration = (int) ($t['duration_seconds'] ?? 0);
                if ($duration > 0 && ($duration > $maxSeconds || $duration < $minSeconds)) {
                    return false;
                }

                $artist = mb_strtolower((string) ($t['artist'] ?? ''));
                if ($artist !== '' && in_array($artist, $blockedArtists, true)) {
                    return false;
                }

                $haystack = mb_strtolower(($t['title'] ?? '').' '.($t['artist'] ?? ''));
                foreach ($blockedKeywords as $keyword) {
                    if ($keyword !== '' && str_contains($haystack, $keyword)) {
                        return false;
                    }
                }

                return true;
            })
            ->take($limit)
            ->values()
            ->all();
    }

    // ------------------------------------------------------------ pomocnicze

    private function mergeUnique(array ...$lists): array
    {
        $seen = [];
        $out = [];

        foreach ($lists as $list) {
            foreach ($list as $track) {
                $id = $track['youtube_id'] ?? null;
                if (! $id || isset($seen[$id])) {
                    continue;
                }
                $seen[$id] = true;
                $out[] = $track;
            }
        }

        return $out;
    }

    private function clean(string $query): string
    {
        return trim(preg_replace('/\s+/u', ' ', $query));
    }

    private function result(string $query, string $layer, array $tracks, ?Party $party, PartySettings $settings): array
    {
        return [
            'query' => $query,
            'layer' => $layer,
            'tracks' => $tracks,
            // Whether the guest may still click "Szukaj na YouTube".
            'youtube_available' => ! $settings->bool('catalog_only')
                && $this->quota->canSpend('search')
                && ($party === null || $this->partyWithinBudget($party, $settings)),
        ];
    }
}
