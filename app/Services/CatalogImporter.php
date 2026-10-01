<?php

namespace App\Services;

use App\Models\CatalogTrack;
use App\Services\YouTube\QuotaGuard;
use App\Services\YouTube\YouTubeClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Filling the track catalogue.
 *
 * THE MOST IMPORTANT THING IN THIS CLASS: we do not build the catalogue by
 * searching.
 *
 *   search.list        = 100 units for ONE track
 *   playlistItems.list =   1 unit for FIFTY tracks
 *
 * The difference is five-thousandfold. Building a catalogue of 5,000 tracks from
 * public playlists costs about 200 units, that is 2% of one day's quota. On the
 * same quota, searching would import 90 tracks and the day would be over.
 */
class CatalogImporter
{
    private const BASE = 'https://www.googleapis.com/youtube/v3';

    public function __construct(
        private YouTubeClient $youtube,
        private QuotaGuard $quota,
        private string $apiKey,
    ) {}

    public function youtube(): YouTubeClient
    {
        return $this->youtube;
    }

    public static function make(): self
    {
        return new self(
            YouTubeClient::make(),
            QuotaGuard::fromConfig(),
            (string) config('services.youtube.key'),
        );
    }

    /**
     * Importuje cala publiczna playliste YouTube do katalogu.
     *
     * @return array{added:int, zaktualizowane:int, pominiete:int, jednostki:int}
     */
    public function importPlaylist(string $playlistId, ?string $genre = null, bool $weddingSafe = true, int $maxItems = 0): array
    {
        $stats = ['added' => 0, 'updated' => 0, 'skipped' => 0, 'units' => 0];
        $pageToken = null;
        $pobrane = 0;

        do {
            if (! $this->quota->canSpend('playlist')) {
                Log::warning('Import przerwany - limit YouTube wyczerpany', ['playlist' => $playlistId]);
                break;
            }

            $response = Http::timeout(30)->retry(3, 2000, throw: false)->get(self::BASE.'/playlistItems', array_filter([
                'part' => 'contentDetails',
                'playlistId' => $playlistId,
                'maxResults' => 50,
                'pageToken' => $pageToken,
                'key' => $this->apiKey,
            ]));

            $this->quota->record('playlist', 'playlist:'.$playlistId);
            $stats['units'] += QuotaGuard::COST['playlist'];

            if (! $response->successful()) {
                Log::warning('Nie udalo sie pobrac playlisty', [
                    'playlist' => $playlistId,
                    'error' => $response->json('error.message'),
                ]);
                break;
            }

            $ids = collect($response->json('items', []))
                ->pluck('contentDetails.videoId')
                ->filter()
                ->values()
                ->all();

            if ($ids !== []) {
                $tracks = $this->youtube->videos($ids);
                $stats['units'] += QuotaGuard::COST['videos'];

                $result = $this->store($tracks, $genre, $weddingSafe);

                $stats['added'] += $result['added'];
                $stats['updated'] += $result['updated'];
                $stats['skipped'] += count($ids) - count($tracks);
            }

            $pobrane += count($ids);

            // Limit chroni przed wciagnieciem kanalu z tysiacami skladanek.
            if ($maxItems > 0 && $pobrane >= $maxItems) {
                break;
            }

            $pageToken = $response->json('nextPageToken');
        } while ($pageToken);

        return $stats;
    }

    /**
     * Writes tracks into the catalogue, weeding out what we could not play anyway.
     */
    public function store(array $tracks, ?string $genre = null, bool $weddingSafe = true): array
    {
        $added = 0;
        $updated = 0;

        foreach ($tracks as $track) {
            if (! $this->isUsable($track)) {
                continue;
            }

            $existing = CatalogTrack::where('youtube_id', $track['youtube_id'])->first();

            $data = [
                'title' => $this->safeText($track['title'], 250),
                'artist' => $this->safeText($track['artist'] ?? null, 250),
                'title_raw' => $this->safeText($track['title_raw'] ?? null, 500),
                'channel' => $this->safeText($track['channel'] ?? null, 250),
                'thumbnail_url' => $track['thumbnail'] ?? CatalogTrack::thumbnailFor($track['youtube_id']),
                'is_topic' => (bool) ($track['is_topic'] ?? false),
                'is_explicit' => CatalogTrack::looksExplicit($track['title'] ?? '', $track['artist'] ?? null),
                'duration_seconds' => min(65535, (int) $track['duration_seconds']),
                'is_embeddable' => true,
                'is_wedding_safe' => $weddingSafe && ! CatalogTrack::looksExplicit($track['title'] ?? '', $track['artist'] ?? null),

                // We set the last-checked marker ALWAYS, even when YouTube did
                // not return the full set of fields. Without it the command that
                // fills in metadata took the same tracks on every run.
                'checked_at' => now(),
            ];

            // Fields the API sometimes withholds: statistics can be hidden by
            // the author, and a snippet can arrive with no channel id. Overwriting
            // them with nothing would erase data we already hold - and view_count
            // is exactly what orders the search results.
            foreach (['channel_id' => 'channel_id', 'view_count' => 'view_count'] as $source => $column) {
                if (($track[$source] ?? null) !== null) {
                    $data[$column] = $track[$source];
                }
            }

            if ($genre) {
                $data['genre'] = $genre;
            }

            if ($existing) {
                // 'source' keeps its original value - refreshing the metadata
                // does not change where a track came to us from.
                $existing->update($data);
                $updated++;
            } else {
                CatalogTrack::create($data + [
                    'youtube_id' => $track['youtube_id'],
                    'source' => 'seed',
                ]);
                $added++;
            }
        }

        return ['added' => $added, 'updated' => $updated];
    }

    /**
     * Cleans text before it is written to the database.
     *
     * Titles from YouTube can carry invalid UTF-8 sequences - most often orphaned
     * halves of surrogate pairs, that is, broken emoji. MySQL in utf8mb4 refuses
     * such data and brings the whole import down.
     *
     * A regular expression with the /u flag cannot catch this, because it returns
     * null on invalid input itself - so we clean with iconv, which simply throws
     * away what it cannot encode.
     */
    private function safeText(?string $text, int $maks): ?string
    {
        if ($text === null || $text === '') {
            return null;
        }

        $clean = @iconv('UTF-8', 'UTF-8//IGNORE', $text);

        if ($clean === false) {
            $clean = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        }

        $clean = trim(mb_substr((string) $clean, 0, $maks));

        return $clean === '' ? null : $clean;
    }

    /**
     * We weed out at the door, so rubbish never reaches a guest: the
     * non-embeddable, the too short, the too long and the compilations.
     */
    private function isUsable(array $track): bool
    {
        if (empty($track['youtube_id']) || empty($track['title'])) {
            return false;
        }

        if (($track['is_embeddable'] ?? false) !== true) {
            return false;
        }

        $duration = (int) ($track['duration_seconds'] ?? 0);

        if ($duration < 60 || $duration > 600) {
            return false;
        }

        $title = mb_strtolower($track['title'].' '.($track['artist'] ?? ''));

        foreach (['1 hour', '10 hours', 'godzin', 'skladanka', 'mix 20', 'full album', 'caly album'] as $bad) {
            if (str_contains($title, $bad)) {
                return false;
            }
        }

        return true;
    }
}
