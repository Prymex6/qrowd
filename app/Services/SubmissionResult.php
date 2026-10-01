<?php

namespace App\Services;

use App\Models\QueueItem;

/**
 * What became of a guest's submission.
 *
 * A new queue entry is not always created - if somebody picks a track that is
 * already waiting, we add a vote to it rather than refusing the choice with an
 * error. The controller has to know which of those happened, so it can show the
 * guest the right message.
 */
final class SubmissionResult
{
    public const ADDED = 'added';   // nowa pozycja w kolejce

    public const PENDING = 'pending'; // czeka na akceptacje hosta

    public const HYPE = 'hype';     // the track was already there, a vote was added

    public function __construct(
        public readonly QueueItem $item,
        public readonly string $action,
    ) {}

    public function isHype(): bool
    {
        return $this->action === self::HYPE;
    }

    public function isPending(): bool
    {
        return $this->action === self::PENDING;
    }
}
