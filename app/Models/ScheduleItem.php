<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScheduleItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'party_id', 'at', 'title', 'screen_message', 'action',
        'youtube_id', 'track_title', 'track_artist', 'payload',
        'is_locked', 'status', 'fired_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'is_locked' => 'boolean',
            'fired_at' => 'datetime',
        ];
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    /**
     * Exactly when this point is due to fire.
     *
     * The schedule holds the bare hour, and a wedding runs past midnight.
     * Comparing hours as text was wrong: for a point at 01:00 the condition
     * "01:00 <= 22:00" holds, so the midnight ritual or the end of the party
     * fired the moment the evening began.
     *
     * So an hour earlier than the party's start is taken to mean the next day.
     */
    public function scheduledFor(?Party $party = null): CarbonInterface
    {
        $party ??= $this->party;
        $start = $party->starts_at ?? $party->created_at ?? now()->startOfDay();

        [$g, $m, $sek] = array_pad(explode(':', (string) $this->at), 3, 0);

        $moment = $start->copy()->setTime((int) $g, (int) $m, (int) $sek);

        return $moment->lt($start) ? $moment->addDay() : $moment;
    }

    /** Whether the time has come for this schedule point to fire. */
    public function isDue(?Party $party = null): bool
    {
        return $this->status === 'pending' && $this->scheduledFor($party)->lte(now());
    }
}
