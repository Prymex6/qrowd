<?php

namespace App\Services;

use App\Models\Guest;
use App\Models\Party;
use App\Models\QueueItem;
use App\Models\SkipVote;

/**
 * Guests voting to skip the track that is playing.
 *
 * The idea is simple: if most of the room wants this over, it is over --
 * without hunting down the host at the bar. The devil is in the denominator.
 *
 * We count ACTIVE guests, meaning those seen within the last three minutes,
 * not everyone who ever scanned the code. Otherwise the threshold would be
 * unreachable by midnight, because the counter would still remember people
 * who went home hours ago.
 *
 * On top of that, a hard floor on the number of votes. The percentage alone,
 * with two people in the room, means one bored person fast-forwards the
 * entire party.
 */
class SkipVoting
{
    public function __construct(private Party $party) {}

    public static function for(Party $party): self
    {
        return new self($party);
    }

    /**
     * Records a vote. Returns the current tally along with whether the
     * threshold has just been crossed.
     */
    public function vote(Guest $guest): array
    {
        $track = $this->party->nowPlaying();

        if (! $track || ! $this->enabled() || ! $this->canVote($guest)) {
            return $this->tally($guest) + ['skipped' => false];
        }

        // firstOrCreate rather than create -- tapping twice should do
        // nothing, not blow up on a database constraint.
        SkipVote::firstOrCreate([
            'queue_item_id' => $track->id,
            'guest_id' => $guest->id,
        ]);

        $state = $this->tally($guest);

        return $state + ['skipped' => $state['reached']];
    }

    /** How many votes, how many are needed, whether this guest already voted. */
    public function tally(?Guest $guest = null): array
    {
        $track = $this->party->nowPlaying();

        if (! $track || ! $this->enabled()) {
            return [
                'enabled' => $this->enabled(),
                'votes' => 0,
                'needed' => 0,
                'inRoom' => 0,
                'tooFewPeople' => false,
                'canVote' => $this->canVote($guest),
                'myVote' => false,
                'reached' => false,
            ];
        }

        $votes = SkipVote::where('queue_item_id', $track->id)->count();
        $needed = $this->required();

        $inRoom = $this->peopleInRoom();

        return [
            'enabled' => true,
            'votes' => $votes,
            'needed' => $needed,
            'inRoom' => $inRoom,
            // The threshold cannot be reached, because there are fewer people
            // in the room than votes required. Without saying so, a "0/3"
            // counter looks broken when it is in fact perfectly correct.
            'tooFewPeople' => $needed > $inRoom,
            'canVote' => $this->canVote($guest),
            'myVote' => $guest
                ? SkipVote::where('queue_item_id', $track->id)->where('guest_id', $guest->id)->exists()
                : false,
            'reached' => $votes >= $needed,
        ];
    }

    /**
     * How many votes are needed at the current turnout.
     *
     * We round UP -- with four people and a 75% threshold that comes to
     * exactly 3, and with five, 4.
     *
     * Only people in the room count: no banned guests, and not the host's
     * laptop previewing the guest view. The host has their own skip button
     * in the panel, so there is no reason for their browser to raise the
     * bar for everyone else.
     *
     * Never fewer than one vote, but never more than there are people to
     * cast them either -- a threshold nobody can reach is a dead button.
     */
    public function required(): int
    {
        $settings = $this->party->settings();
        $inRoom = $this->peopleInRoom();

        $byPercent = (int) ceil($inRoom * $settings->int('skip_vote_percent') / 100);
        $floor = min($settings->int('skip_vote_min'), max($inRoom, 1));

        return max($floor, $byPercent, 1);
    }

    /** Cleared when the track changes -- votes never carry over to the next one. */
    public function forget(QueueItem $track): void
    {
        SkipVote::where('queue_item_id', $track->id)->delete();
    }

    /**
     * Whether this guest may vote at all.
     *
     * Only someone who counts toward the threshold gets to vote. Otherwise
     * the arithmetic stops adding up: the host's laptop would contribute
     * votes to a denominator it is not part of, and two browser windows
     * would be enough to fast-forward the whole party.
     */
    public function canVote(?Guest $guest): bool
    {
        return $guest !== null && ! $guest->is_banned && $guest->counts_in_room;
    }

    /**
     * How many people are in the room RIGHT NOW.
     *
     * Not "how many guests ever scanned the code", but how many have the app
     * open within the last three minutes -- excluding banned guests and the
     * host's laptop.
     */
    public function peopleInRoom(): int
    {
        return $this->party->guests()
            ->countedInRoom()
            ->where('last_seen_at', '>', now()->subMinutes(3))
            ->count();
    }

    private function enabled(): bool
    {
        return $this->party->settings()->bool('skip_vote_enabled');
    }
}
