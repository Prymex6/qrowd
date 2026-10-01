<?php

namespace App\Services\YouTube;

use App\Models\CatalogTrack;
use App\Support\TrackTitle;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * A thin layer over the YouTube Data API v3.
 *
 * We use the official API ONLY. No stream downloading, no yt-dlp -- music
 * never passes through our server, because that would turn a tool into
 * a distributor of other people's recordings.
 */
class YouTubeClient
{
    private const BASE = 'https://www.googleapis.com/youtube/v3';

    public function __construct(
        private string $apiKey,
        private QuotaGuard $quota,
    ) {}

    public static function make(): self
    {
        return new self(
            (string) config('services.youtube.key'),
            QuotaGuard::fromConfig(),
        );
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '';
    }

    /**
     * Search -- 100 units per query. Spend sparingly.
     *
     * @return array<int, array> lista utworow w naszym formacie
     */
    public function search(string $query, int $limit = 10, ?int $partyId = null): array
    {
        if (! $this->isConfigured() || ! $this->quota->canSpend('search')) {
            return [];
        }

        $response = Http::timeout(20)->retry(2, 1500, throw: false)->get(self::BASE.'/search', [
            'part' => 'snippet',
            'q' => $query,
            'type' => 'video',
            'videoCategoryId' => 10,       // the Music category only
            'videoEmbeddable' => 'true',   // drop what we could not play anyway
            'maxResults' => min($limit, 25),
            'regionCode' => 'PL',
            'relevanceLanguage' => 'pl',
            'key' => $this->apiKey,
        ]);

        $this->quota->record('search', $query, $partyId);

        if (! $response->successful()) {
            Log::warning('YouTube search nie powiodl sie', [
                'status' => $response->status(),
                'body' => $response->json('error.message'),
            ]);

            return [];
        }

        $ids = collect($response->json('items', []))
            ->pluck('id.videoId')
            ->filter()
            ->values()
            ->all();

        if ($ids === []) {
            return [];
        }

        // Pull duration and embeddable status -- that costs only 1 unit.
        return $this->videos($ids, $partyId);
    }

    /**
     * Video details -- 1 unit per query, up to 50 ids.
     *
     * @param  array<int, string>  $ids
     * @return array<int, array>
     */
    public function videos(array $ids, ?int $partyId = null): array
    {
        if ($ids === [] || ! $this->isConfigured() || ! $this->quota->canSpend('videos')) {
            return [];
        }

        // Thirty seconds and three attempts: a response for 50 tracks with the
        // statistics field weighs about half a megabyte, and on a slower line
        // eight seconds was not enough. A single timeout could kill an import
        // that had been running for half an hour.
        $response = Http::timeout(30)->retry(3, 2000, throw: false)->get(self::BASE.'/videos', [
            // statistics does not raise the cost -- videos.list is 1 unit for up to
            // 50 videos, no matter how many fields are asked for.
            'part' => 'snippet,contentDetails,status,statistics',
            'id' => implode(',', array_slice($ids, 0, 50)),
            'key' => $this->apiKey,
        ]);

        $this->quota->record('videos', null, $partyId);

        if (! $response->successful()) {
            return [];
        }

        return collect($response->json('items', []))
            ->map(fn (array $item) => $this->normalize($item))
            ->filter(fn (array $t) => $t['is_embeddable'] && $t['duration_seconds'] > 0)
            ->values()
            ->all();
    }

    /** Sprowadza odpowiedz YouTube do naszego jednego formatu utworu. */
    private function normalize(array $item): array
    {
        $snippet = $item['snippet'] ?? [];
        $rawTitle = $snippet['title'] ?? '';
        $channel = $snippet['channelTitle'] ?? null;

        [$artist, $cleanTitle] = $this->splitTitle($rawTitle, $channel);

        return [
            'youtube_id' => $item['id'],
            'title' => $cleanTitle,
            'artist' => $artist,

            // The original stays untouched -- the cleaned version drives search and
            // display, but the guest needs something to confirm this is THAT song.
            'title_raw' => $rawTitle,
            'channel' => $channel,
            'channel_id' => $snippet['channelId'] ?? null,
            'thumbnail' => CatalogTrack::thumbnailFor($item['id']),

            // Clean studio audio, with no spoken intro from a music video.
            'is_topic' => CatalogTrack::looksLikeCleanAudio($channel, $rawTitle),

            'duration_seconds' => $this->parseDuration($item['contentDetails']['duration'] ?? 'PT0S'),
            'view_count' => isset($item['statistics']['viewCount'])
                ? (int) $item['statistics']['viewCount']
                : null,
            'is_embeddable' => (bool) ($item['status']['embeddable'] ?? false),

            // We compute the same thing as the catalogue import does. This used to
            // be a hardcoded false, so the profanity filter worked ONLY on tracks
            // already sitting in the catalogue -- a fresh YouTube result walked
            // straight through a wedding without stopping.
            'is_explicit' => CatalogTrack::looksExplicit($cleanTitle, $artist)
                || CatalogTrack::looksExplicit($rawTitle, $channel),
            'source' => 'youtube',
        ];
    }

    /** Podzial na wykonawce i tytul - cala logika siedzi w TrackTitle. */
    private function splitTitle(string $title, ?string $channel): array
    {
        return TrackTitle::split($title, $channel);
    }

    /**
     * Finds an artist's channel by name.
     *
     * Costs 100 units -- the most expensive operation in the whole system, so
     * we only call it from the artist queue spread across several days.
     * In return, one such query unlocks an entire discography, which we then
     * pull at 1 unit per 50 tracks.
     *
     * @return array{channel_id: string, title: string}|null
     */
    public function findArtistChannel(string $name): ?array
    {
        if (! $this->isConfigured() || ! $this->quota->canSpend('search')) {
            return null;
        }

        $response = Http::timeout(20)->retry(2, 1500, throw: false)->get(self::BASE.'/search', [
            'part' => 'snippet',
            'q' => $name,
            'type' => 'channel',
            'maxResults' => 5,
            'key' => $this->apiKey,
        ]);

        $this->quota->record('search', 'kanal:'.$name);

        if (! $response->successful()) {
            return null;
        }

        $candidates = $response->json('items', []);

        if ($candidates === []) {
            return null;
        }

        // We score the candidates and take the best. A "- Topic" channel is an
        // automatic discography from the label: clean audio off the record, with
        // no spoken intro and no music-video dialogue. We always prefer it to the
        // artist's own channel, and that to anything else.
        $best = null;
        $bestScore = -1;

        $wanted = mb_strtolower(trim($name));

        foreach ($candidates as $k) {
            $title = trim($k['snippet']['title'] ?? '');
            $id = $k['snippet']['channelId'] ?? ($k['id']['channelId'] ?? '');

            if ($id === '') {
                continue;
            }

            $low = mb_strtolower($title);
            $bezTopic = mb_strtolower(trim(preg_replace('/\s*-\s*Topic\s*$/iu', '', $title)));

            $result = 0;

            if (str_ends_with($low, '- topic')) {
                $result += 100;                       // clean studio audio
            }
            if ($bezTopic === $wanted) {
                $result += 50;                        // the name matches exactly
            } elseif (str_contains($bezTopic, $wanted) || str_contains($wanted, $bezTopic)) {
                $result += 20;
            }
            if (str_ends_with($low, 'vevo')) {
                $result += 10;                        // official, but these are music videos
            }

            if ($result > $bestScore) {
                $bestScore = $result;
                $best = ['channel_id' => $id, 'title' => $title];
            }
        }

        return $best;
    }

    /**
     * Turns channel ids into their "all uploads" playlists.
     *
     * Costs 1 unit per 50 channels. That lets a single discovered track open
     * an artist's whole discography practically for free, instead of gathering
     * it piece by piece from random compilations.
     *
     * @param  array<int, string>  $channelIds
     * @return array<string, array{playlist: string, name: string}> channelId => dane
     */
    public function uploadsPlaylists(array $channelIds): array
    {
        if ($channelIds === [] || ! $this->isConfigured() || ! $this->quota->canSpend('videos')) {
            return [];
        }

        $response = Http::timeout(20)->retry(2, 1500, throw: false)->get(self::BASE.'/channels', [
            'part' => 'contentDetails,snippet',
            'id' => implode(',', array_slice($channelIds, 0, 50)),
            'key' => $this->apiKey,
        ]);

        $this->quota->record('videos', 'channels');

        if (! $response->successful()) {
            return [];
        }

        $result = [];

        foreach ($response->json('items', []) as $item) {
            $playlist = $item['contentDetails']['relatedPlaylists']['uploads'] ?? null;

            if ($playlist) {
                $result[$item['id']] = [
                    'playlist' => $playlist,
                    'name' => $item['snippet']['title'] ?? '',
                ];
            }
        }

        return $result;
    }

    /** Zamienia czas ISO 8601 (PT3M31S) na sekundy. */
    public function parseDuration(string $iso): int
    {
        if (! preg_match('/PT(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?/', $iso, $m)) {
            return 0;
        }

        return ((int) ($m[1] ?? 0)) * 3600 + ((int) ($m[2] ?? 0)) * 60 + ((int) ($m[3] ?? 0));
    }
}
