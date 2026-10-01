<?php

namespace App\Models;

use Database\Factories\GuestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Guest extends Model
{
    use HasFactory;

    protected $fillable = [
        'party_id', 'nickname', 'avatar', 'device_hash', 'is_banned', 'last_seen_at',
        'counts_in_room',
    ];

    protected function casts(): array
    {
        return [
            'is_banned' => 'boolean',
            'counts_in_room' => 'boolean',
            'last_seen_at' => 'datetime',
        ];
    }

    /**
     * The guests counted as people in the room.
     *
     * A banned guest drops out, and so does the host's device - the laptop the
     * host watches the guest view on is not a person on the dance floor and must
     * not raise the skip-vote threshold.
     */
    public function scopeCountedInRoom($query)
    {
        return $query->where('is_banned', false)->where('counts_in_room', true);
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function queueItems(): HasMany
    {
        return $this->hasMany(QueueItem::class);
    }

    public function votes(): HasMany
    {
        return $this->hasMany(Vote::class);
    }

    /** How many of this guest's submissions are waiting or playing right now. */
    public function activeSubmissions(): int
    {
        return $this->queueItems()->whereIn('status', ['pending', 'queued', 'playing'])->count();
    }

    /** How many they added across the whole evening - for the overall limit. */
    public function totalSubmissions(): int
    {
        return $this->queueItems()->whereNotIn('status', ['vetoed'])->count();
    }

    public function isOnline(): bool
    {
        return $this->last_seen_at && $this->last_seen_at->gt(now()->subMinutes(3));
    }

    public static function randomNickname(): string
    {
        $adjectives = ['Taneczny', 'Szalony', 'Nocny', 'Zloty', 'Dziki', 'Rytmiczny', 'Parkietowy'];
        $nouns = ['Niedzwiedz', 'Sokol', 'Wilk', 'Lis', 'RyS', 'Jastrzab', 'Zubr'];

        return $adjectives[array_rand($adjectives)].$nouns[array_rand($nouns)];
    }

    protected static function newFactory(): GuestFactory
    {
        return GuestFactory::new();
    }
}
