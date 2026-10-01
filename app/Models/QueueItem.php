<?php

namespace App\Models;

use Database\Factories\QueueItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QueueItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'party_id', 'guest_id', 'catalog_track_id',
        'youtube_id', 'local_path', 'title', 'artist', 'duration_seconds',
        'hype_count', 'score', 'status', 'is_pinned', 'source',
        'queued_at', 'started_at', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'is_pinned' => 'boolean',
            'score' => 'float',
            'queued_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }

    public function catalogTrack(): BelongsTo
    {
        return $this->belongsTo(CatalogTrack::class);
    }

    public function votes(): HasMany
    {
        return $this->hasMany(Vote::class);
    }

    /** Ile minut czeka w kolejce - podstawa starzenia w rankingu. */
    public function waitingMinutes(): float
    {
        $since = $this->queued_at ?? $this->created_at;

        return $since ? $since->floatDiffInMinutes(now()) : 0.0;
    }

    public function isPlaying(): bool
    {
        return $this->status === 'playing';
    }

    public function votedBy(?Guest $guest): bool
    {
        if (! $guest) {
            return false;
        }

        return $this->votes()->where('guest_id', $guest->id)->exists();
    }

    public function fullTitle(): string
    {
        return $this->artist ? "{$this->artist} - {$this->title}" : $this->title;
    }

    protected static function newFactory(): QueueItemFactory
    {
        return QueueItemFactory::new();
    }
}
