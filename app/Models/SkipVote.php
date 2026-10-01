<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A vote to skip the track now playing.
 *
 * A table apart from `votes` (hype), because the intent is the opposite and it
 * counts differently: hypes add up across the whole queue, while skip votes
 * concern one track and vanish with it.
 */
class SkipVote extends Model
{
    use HasFactory;

    protected $fillable = ['queue_item_id', 'guest_id'];

    public function queueItem(): BelongsTo
    {
        return $this->belongsTo(QueueItem::class);
    }

    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }
}
