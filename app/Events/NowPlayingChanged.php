<?php

namespace App\Events;

use App\Models\Party;
use App\Models\QueueItem;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NowPlayingChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Party $party, public ?QueueItem $item) {}

    public function broadcastOn(): array
    {
        return [new Channel('party.'.$this->party->code)];
    }

    public function broadcastAs(): string
    {
        return 'playing.changed';
    }

    public function broadcastWith(): array
    {
        return [
            'track' => $this->item ? [
                'id' => $this->item->id,
                'youtube_id' => $this->item->youtube_id,
                'title' => $this->item->title,
                'artist' => $this->item->artist,
                'duration' => $this->item->duration_seconds,
                'submittedBy' => $this->item->guest?->nickname,
            ] : null,
        ];
    }
}
