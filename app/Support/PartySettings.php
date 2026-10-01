<?php

namespace App\Support;

use Illuminate\Contracts\Support\Arrayable;

/**
 * Every party setting in one place.
 *
 * They live as JSON in parties.settings, but in code they always pass
 * through this class -- so an old party saved before a new option existed
 * gets a sensible default rather than a null.
 */
class PartySettings implements Arrayable
{
    public const DEFAULTS = [
        // --- pacing ---
        'set_length' => 8,      // tracks in a row before a break
        'break_seconds' => 90,
        'max_track_seconds' => 300,    // anything longer gets trimmed
        'min_track_seconds' => 60,     // drops skits and junk
        'max_video_seconds' => 480,    // drops "1 hour" compilations
        'crossfade_seconds' => 3,
        'fast_mode' => false,  // every track only ~2 minutes
        'fast_mode_seconds' => 120,
        'auto_fill' => true,   // self-serve when the queue runs dry
        // After a mute the track resumes where it stopped, like any player.
        // The host has a separate skip button for starting something over, so
        // muting does not need to do two jobs at once.
        'resume_from_start' => false,

        // --- queue and voting ---
        'max_active_per_guest' => 2,
        'max_total_per_guest' => 10,
        'hype_weight' => 1.0,
        'aging_strength' => 'medium', // weak | medium | strong
        'artist_cooldown' => 5,     // same artist no more often than every N tracks
        'guest_cooldown' => 2,     // not three tracks from the same person in a row
        'repeat_block_hours' => 3,
        'entry_threshold' => 0,     // hypes required to enter the queue at all
        'auto_expire_minutes' => 30,    // dead submissions with no hypes drop out
        'allow_unvote' => true,

        // --- skip voting ---
        // The room ends a track nobody wants without hunting down the host at
        // the bar. The threshold counts ACTIVE guests -- those seen in the last
        // three minutes -- otherwise by midnight the counter would still be
        // remembering people who went home hours ago.
        'skip_vote_enabled' => true,
        'skip_vote_percent' => 75,
        // Floor on the number of votes.
        //
        // Defaults to 1, so the percentage alone decides. When one person with
        // the app is in the room, they ARE the entire audience -- holding them
        // to a rigid three-vote threshold merely killed the feature early in the
        // evening and at small gatherings.
        //
        // Anyone wanting a firmer guard at a large party raises it with the
        // slider in settings.
        'skip_vote_min' => 1,
        'show_submitter' => true,
        'visible_queue_length' => 20,
        'youtube_search_budget' => 25,  // how often one party may reach into all of YouTube

        // --- content filters ---
        'filter_explicit' => true,
        'catalog_only' => false,

        // Where music comes from: 'youtube' or 'dysk'. No mixing.
        //
        // YouTube by default, because that is the whole point: a guest types
        // anything and it plays. Disk is the escape hatch -- a wedding venue can
        // turn out to have no internet, and then the party stalls without a library.
        //
        // There is deliberately NO mixed mode. A party happens in one of two
        // worlds: either the line is up and we play from YouTube, or it is down
        // and we play from disk. A blend would mean half the search results stop
        // working at the exact moment the wifi drops -- which is precisely when
        // a guest most needs something, anything, to play.
        'music_source' => 'youtube', // true = guests may NOT reach the whole of YouTube
        'moderation' => false, // kazda wrzutka czeka na akceptacje hosta
        'blocked_keywords' => ['1 hour', '10 hours', 'nightcore', 'sped up'],

        // --- guest photos ---
        'photos_enabled' => true,
        'photo_moderation' => false,  // photos wait for the host to approve them
        'photos_visible_to_guests' => true, // wspolna galeria zamiast prywatnych zbiorow
        'photo_captions' => true,
        'photo_max_per_guest' => 20,
        'photo_retention_days' => 30,      // po tylu dniach kasujemy z serwera
        'photos_on_screen' => true,    // sciana zdjec w przerwach

        // --- room screen ---
        'screen_show_qr' => true,
        'screen_show_submitter' => true,
    ];

    /**
     * Which genres the system may pick from on its own when the queue empties.
     *
     * This is NOT a filter for guests -- they may search for whatever they
     * like. It is the list that is safe to draw from unsupervised. With tens
     * of thousands of tracks in the database, drawing from all of them ends
     * with a children's cartoon song in the middle of the first dance.
     */
    public const AUTO_GENRES = [
        'wedding' => ['wesele', 'biesiada', 'disco-polo', 'pop-polski', 'klasyki-pl', 'klasyki-zagraniczne', 'polski-rock'],
        'corporate' => ['pop-polski', 'klasyki-pl', 'klasyki-zagraniczne', 'zagraniczne', 'polski-rock'],
        'birthday' => ['pop-polski', 'klubowa', 'zagraniczne', 'disco-polo', 'klasyki-zagraniczne'],
        'houseparty' => ['klubowa', 'zagraniczne', 'pop-polski', 'disco-polo', 'rap-polski', 'rap'],
    ];

    /** How fast an old track climbs despite few hypes (points per minute). */
    public const AGING = [
        'weak' => 0.08,
        'medium' => 0.20,
        'strong' => 0.45,
    ];

    /**
     * Ready-made sets per party type -- so the host need not touch 30 sliders.
     */
    public const PRESETS = [
        'wedding' => [
            'moderation' => true,
            'photo_moderation' => true,
            'skip_vote_enabled' => false,
            'filter_explicit' => true,
            'catalog_only' => false,
            'set_length' => 10,
            'break_seconds' => 60,
            'max_track_seconds' => 300,
            'artist_cooldown' => 6,
        ],
        'corporate' => [
            'moderation' => true,
            'photo_moderation' => true,
            'filter_explicit' => true,
            'catalog_only' => false,
            'set_length' => 8,
        ],
        'birthday' => [
            'moderation' => false,
            'filter_explicit' => false,
            'catalog_only' => false,
            'set_length' => 8,
        ],
        'houseparty' => [
            'moderation' => false,
            'filter_explicit' => false,
            'catalog_only' => false,
            'set_length' => 12,
            'break_seconds' => 0,
            'max_active_per_guest' => 3,
        ],
    ];

    public function __construct(private array $values = []) {}

    public static function make(?array $stored, ?string $type = null): self
    {
        $values = self::DEFAULTS;

        if ($type && isset(self::PRESETS[$type])) {
            $values = array_merge($values, self::PRESETS[$type]);
        }

        return new self(array_merge($values, $stored ?? []));
    }

    public function get(string $key, mixed $fallback = null): mixed
    {
        return $this->values[$key] ?? self::DEFAULTS[$key] ?? $fallback;
    }

    public function int(string $key): int
    {
        return (int) $this->get($key);
    }

    public function float(string $key): float
    {
        return (float) $this->get($key);
    }

    public function bool(string $key): bool
    {
        return (bool) $this->get($key);
    }

    /** Ile punktow na minute dorzuca starzenie przy aktualnym ustawieniu. */
    public function agingPerMinute(): float
    {
        return self::AGING[$this->get('aging_strength')] ?? self::AGING['medium'];
    }

    /** Ile realnie gramy z utworu - uwzglednia tryb szybkiej jazdy i twardy limit. */
    public function playableSeconds(int $duration): int
    {
        $limit = $this->bool('fast_mode')
            ? min($this->int('fast_mode_seconds'), $this->int('max_track_seconds'))
            : $this->int('max_track_seconds');

        return $duration > 0 ? min($duration, $limit) : $limit;
    }

    public function toArray(): array
    {
        return $this->values;
    }
}
