/**
 * A party's default settings - a mirror of PartySettings::DEFAULTS.
 *
 * We keep them here so the panel's tests need not stand PHP up. The section
 * tests do not depend on the particular values - they need only the full set of
 * keys, so the form builds the same way it does in the application.
 */
export const defaultSettings = {
    "set_length": 8,
    "break_seconds": 90,
    "max_track_seconds": 300,
    "min_track_seconds": 60,
    "max_video_seconds": 480,
    "crossfade_seconds": 3,
    "fast_mode": false,
    "fast_mode_seconds": 120,
    "auto_fill": true,
    "resume_from_start": false,
    "max_active_per_guest": 2,
    "max_total_per_guest": 10,
    "hype_weight": 1,
    "aging_strength": "medium",
    "artist_cooldown": 5,
    "guest_cooldown": 2,
    "repeat_block_hours": 3,
    "entry_threshold": 0,
    "auto_expire_minutes": 30,
    "allow_unvote": true,
    "skip_vote_enabled": true,
    "skip_vote_percent": 75,
    "skip_vote_min": 1,
    "show_submitter": true,
    "visible_queue_length": 20,
    "youtube_search_budget": 25,
    "filter_explicit": true,
    "catalog_only": false,
    "music_source": "youtube",
    "moderation": false,
    "blocked_keywords": [
        "1 hour",
        "10 hours",
        "nightcore",
        "sped up"
    ],
    "photos_enabled": true,
    "photo_moderation": false,
    "photos_visible_to_guests": true,
    "photo_captions": true,
    "photo_max_per_guest": 20,
    "photo_retention_days": 30,
    "photos_on_screen": true,
    "screen_show_qr": true,
    "screen_show_submitter": true
};
