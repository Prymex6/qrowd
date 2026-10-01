<?php

namespace App\Models;

use App\Support\PartySettings;
use Database\Factories\PartyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class Party extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'code', 'slug', 'name', 'type', 'mode', 'status', 'plan',
        'max_guests', 'player_token', 'player_seen_at',
        'starts_at', 'ends_at', 'ended_at', 'paused_at', 'settings',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'ended_at' => 'datetime',
            'paused_at' => 'datetime',
            'player_seen_at' => 'datetime',
        ];
    }

    // -------------------------------------------------- relacje

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function guests(): HasMany
    {
        return $this->hasMany(Guest::class);
    }

    public function queueItems(): HasMany
    {
        return $this->hasMany(QueueItem::class);
    }

    public function photos(): HasMany
    {
        return $this->hasMany(Photo::class);
    }

    public function blocks(): HasMany
    {
        return $this->hasMany(PartyBlock::class);
    }

    public function scheduleItems(): HasMany
    {
        return $this->hasMany(ScheduleItem::class)->orderBy('at');
    }

    // -------------------------------------------------- ustawienia

    public function settings(): PartySettings
    {
        return PartySettings::make($this->settings, $this->type);
    }

    public function updateSettings(array $changes): void
    {
        $this->update(['settings' => array_merge($this->settings ?? [], $changes)]);
    }

    // -------------------------------------------------- kolejka

    /**
     * Tidying up after a deleted party.
     *
     * The rows disappear by the foreign key's cascade, but files on disk know
     * nothing of foreign keys - and a cascade in the database fires no model
     * events, so Photo::deleteWithFiles() would never run here. Without this hook
     * guests' photos stayed on the disk forever, though the party no longer
     * exists. Those are people's likenesses, so they must go with it.
     */
    protected static function booted(): void
    {
        static::deleting(function (self $party) {
            Storage::deleteDirectory(Photo::directory($party));
        });
    }

    public function nowPlaying(): ?QueueItem
    {
        return $this->queueItems()->where('status', 'playing')->first();
    }

    /** The waiting queue, ordered the way it will play. */
    public function queue()
    {
        return $this->queueItems()
            ->where('status', 'queued')
            ->orderByDesc('is_pinned')
            ->orderByDesc('score')
            ->orderBy('queued_at');
    }

    public function pendingItems()
    {
        return $this->queueItems()->where('status', 'pending')->orderBy('created_at');
    }

    /**
     * The tracks that played. Breaks are recorded as entries in the history (they
     * reset the counter to the next break), but they are not music and must not
     * inflate the "tracks played" figure.
     */
    public function playedItems()
    {
        return $this->queueItems()
            ->where('status', 'played')
            ->where('title', '!=', 'PRZERWA')
            ->orderByDesc('started_at');
    }

    // -------------------------------------------------- stan

    public function isLive(): bool
    {
        return $this->status === 'live';
    }

    public function acceptsSubmissions(): bool
    {
        return in_array($this->status, ['live', 'paused', 'scheduled'], true);
    }

    /** Ile utworow zagralo od ostatniej przerwy - steruje wejsciem przerwy. */
    public function tracksSinceBreak(): int
    {
        $lastBreak = $this->queueItems()
            ->where('source', 'auto')
            ->where('title', 'PRZERWA')
            ->latest('started_at')
            ->value('started_at');

        return $this->queueItems()
            ->where('status', 'played')
            ->when($lastBreak, fn ($q) => $q->where('started_at', '>', $lastBreak))
            ->count();
    }

    // -------------------------------------------------- pomocnicze

    public static function generateCode(): string
    {
        // Without the characters that confuse in print: 0/O, 1/I/L.
        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

        do {
            $code = '';
            for ($i = 0; $i < 6; $i++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
        } while (self::where('code', $code)->exists());

        return $code;
    }

    public static function generatePlayerToken(): string
    {
        return Str::random(40);
    }

    public function joinUrl(): string
    {
        return url('/j/'.$this->code);
    }

    public function screenUrl(): string
    {
        return url('/screen/'.$this->code);
    }

    protected static function newFactory(): PartyFactory
    {
        return PartyFactory::new();
    }
}
