<?php

namespace App\Models;

use Database\Factories\CatalogTrackFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * The track catalogue -- search layer one.
 *
 * Every hit here saves 100 units of YouTube quota. At 10,000 units a day
 * the catalogue is not an optimisation -- it is what makes the product
 * work at all.
 */
class CatalogTrack extends Model
{
    use HasFactory;

    protected $fillable = [
        'youtube_id', 'local_path', 'user_id', 'title', 'artist', 'title_raw', 'channel', 'channel_id', 'thumbnail_url', 'is_topic',
        'duration_seconds', 'start_offset', 'end_offset', 'genre',
        'is_explicit', 'is_embeddable', 'is_wedding_safe', 'is_active', 'is_curated',
        'play_count', 'view_count', 'source', 'checked_at',
    ];

    protected function casts(): array
    {
        return [
            'is_explicit' => 'boolean',
            'is_topic' => 'boolean',
            'is_curated' => 'boolean',
            'is_embeddable' => 'boolean',
            'is_wedding_safe' => 'boolean',
            'is_active' => 'boolean',
            'checked_at' => 'datetime',
        ];
    }

    /**
     * Full-text search in BOOLEAN mode.
     *
     * Natural mode matches "anything that fits", so a search for "sto lat"
     * returned tracks containing just the word "lat". At a wedding that means
     * a guest looking for "Sto lat" gets random disco polo.
     *
     * Boolean mode requires EVERY word (+word*), so a result either contains
     * all parts of the query or does not appear at all.
     */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        $expression = self::booleanExpression($term);

        if ($expression === '') {
            return $query->whereRaw('1 = 0');
        }

        return $query
            ->whereRaw('MATCH(title, artist) AGAINST (? IN BOOLEAN MODE)', [$expression])
            // Boolean mode already demanded every word of the query, so everything
            // reaching this point is relevant. Order therefore comes from POPULARITY,
            // just like on YouTube. Without it an obscure cover ranked level with the
            // original and the guest could not find what they actually meant.
            ->orderByRaw('COALESCE(view_count, 0) DESC')
            // At comparable popularity, clean studio audio wins.
            ->orderByDesc('is_topic')
            ->orderByRaw('MATCH(title, artist) AGAINST (? IN BOOLEAN MODE) DESC', [$expression]);
    }

    /**
     * Turns "sto lat" into "+sto* +lat*".
     * Words shorter than 3 characters are dropped -- InnoDB does not index them.
     */
    public static function booleanExpression(string $term): string
    {
        $term = preg_replace('/[+\-><\(\)~*\"@]+/u', ' ', $term) ?? $term;
        $words = preg_split('/\s+/u', trim($term), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $parts = [];
        foreach ($words as $word) {
            if (mb_strlen($word) >= 3) {
                $parts[] = '+'.$word.'*';
            }
        }

        return implode(' ', $parts);
    }

    /** A fallback substring search - for short queries FULLTEXT does not catch. */
    public function scopeSearchLike(Builder $query, string $term): Builder
    {
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], trim($term)).'%';

        return $query
            ->where(function (Builder $q) use ($like) {
                $q->where('title', 'like', $like)->orWhere('artist', 'like', $like);
            })
            ->orderByRaw('COALESCE(view_count, 0) DESC')
            ->orderByDesc('is_topic');
    }

    /**
     * Narrows the catalogue to the source the host chose.
     *
     * Mutually exclusive, with no middle mode: under 'disk' YouTube tracks may
     * not even appear in search, because a guest would tap something the
     * player cannot play without internet. Under 'youtube' the reverse -- disk
     * files would only muddy the results.
     */
    public function scopeFromSource($query, string $source, ?int $hostId = null)
    {
        if ($source !== 'disk') {
            return $query->whereNull('local_path');
        }

        // The disk library is private -- these are files sitting on one specific
        // host's laptop. We never show someone else's, because the player would
        // have nothing to open.
        return $query->whereNotNull('local_path')->where('user_id', $hostId);
    }

    public function scopePlayable(Builder $query): Builder
    {
        return $query->where('is_active', true)->where('is_embeddable', true);
    }

    public function fullTitle(): string
    {
        return $this->artist ? "{$this->artist} - {$this->title}" : $this->title;
    }

    /**
     * The track thumbnail.
     *
     * The URL follows directly from the video id, so it costs not a single
     * unit of API quota and needs storing nowhere.
     *
     * mqdefault (320x180) is the best compromise for a result list: hqdefault
     * has black bars, and maxresdefault does not exist for every video.
     */
    public function thumbnail(string $quality = 'mqdefault'): string
    {
        // A stored URL wins -- once images are pulled in locally it points at our
        // own file rather than at Google's servers.
        return $this->thumbnail_url
            ?: "https://i.ytimg.com/vi/{$this->youtube_id}/{$quality}.jpg";
    }

    public static function thumbnailFor(string $youtubeId, string $quality = 'mqdefault'): string
    {
        return "https://i.ytimg.com/vi/{$youtubeId}/{$quality}.jpg";
    }

    /** Ile sekund realnie gramy po uwzglednieniu przyciecia. */
    public function playableLength(): int
    {
        return max(0, $this->duration_seconds - $this->start_offset - $this->end_offset);
    }

    /**
     * Whether the title suggests adult content.
     *
     * The YouTube Data API returns no explicit marking for music in the fields
     * we fetch -- all we have is the title itself. This detection is inherently
     * incomplete, but better than the state it replaced, in which the profanity
     * filter blocked absolutely nothing.
     */
    public static function looksExplicit(?string $title, ?string $artist = null): bool
    {
        $t = mb_strtolower(($title ?? '').' '.($artist ?? ''));

        // Explicit markings labels actually use.
        foreach (['explicit', 'parental advisory', '18+', 'uncensored'] as $marker) {
            if (str_contains($t, $marker)) {
                return true;
            }
        }

        // Profanity in the title. The list is deliberately short -- this is about
        // obvious cases, not about censoring everything.
        $wulgarne = ['fuck', 'shit', 'bitch', 'nigga', 'kurw', 'jeban', 'pierdol', 'chuj', 'huj', 'suka'];

        foreach ($wulgarne as $word) {
            if (str_contains($t, $word)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a track is clean studio audio.
     *
     * "- Topic" channels are generated automatically from the label's release,
     * so they carry no spoken intro and no dialogue from a music video. It is
     * the most reliable signal available short of listening to every track.
     */
    public static function looksLikeCleanAudio(?string $channel, ?string $title): bool
    {
        if ($channel && str_ends_with(trim($channel), '- Topic')) {
            return true;
        }

        $t = mb_strtolower((string) $title);

        foreach (['official audio', 'audio oficjalne', 'lyric video', 'visualizer'] as $sygnal) {
            if (str_contains($t, $sygnal)) {
                return true;
            }
        }

        return false;
    }

    protected static function newFactory(): CatalogTrackFactory
    {
        return CatalogTrackFactory::new();
    }
}
