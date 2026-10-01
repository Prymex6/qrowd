<?php

namespace App\Events;

use App\Models\Party;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * The queue changed - the guests' phones and the screen are to refresh.
 *
 * We send the bare signal, not the whole queue: with a hundred guests,
 * broadcasting the full list on every hype would choke the venue's connection.
 */
class QueueUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Party $party, public string $reason = 'update') {}

    public function broadcastOn(): array
    {
        return [new Channel('party.'.$this->party->code)];
    }

    public function broadcastAs(): string
    {
        return 'queue.changed';
    }

    public function broadcastWith(): array
    {
        return ['reason' => $this->reason, 'time' => now()->timestamp];
    }
}
