<?php

namespace App\Exceptions;

use Exception;

/**
 * The errors that have to reach a guest in Polish, right on their phone screen.
 */
class QueueException extends Exception
{
    public function __construct(string $message, public readonly string $reason = 'general')
    {
        parent::__construct($message);
    }

    public static function limitReached(int $limit): self
    {
        return new self(
            "Masz już {$limit} kawałki w kolejce. Poczekaj, aż zagrają.",
            'limit_active'
        );
    }

    public static function totalLimitReached(int $limit): self
    {
        return new self(
            "Wykorzystałeś swoje {$limit} wrzutek na ten wieczór.",
            'limit_total'
        );
    }

    public static function alreadyQueued(): self
    {
        return new self('Ten kawałek już czeka w kolejce — daj mu hype!', 'duplicate');
    }

    public static function alreadyYours(): self
    {
        return new self('Ten kawałek już wrzuciłeś — czeka w kolejce.', 'already_yours');
    }

    public static function alreadyVoted(): self
    {
        return new self('Już dałeś temu kawałkowi hype.', 'already_voted');
    }

    public static function playedRecently(int $hours): self
    {
        return new self("Ten kawałek już dziś grał. Wróci za {$hours} h.", 'played_recently');
    }

    public static function tooLong(int $maxSeconds): self
    {
        $min = intdiv($maxSeconds, 60);

        return new self("Za długi kawałek — organizator ustawił limit {$min} min.", 'too_long');
    }

    public static function tooShort(): self
    {
        return new self('Za krótki kawałek — to chyba nie jest cała piosenka.', 'too_short');
    }

    public static function blocked(): self
    {
        return new self('Organizator zablokował ten utwór albo wykonawcę.', 'blocked');
    }

    public static function explicit(): self
    {
        return new self('Ten kawałek ma wulgaryzmy, a organizator je wyłączył.', 'explicit');
    }

    public static function notEmbeddable(): self
    {
        return new self('Tego utworu nie da się odtworzyć poza YouTube.', 'not_embeddable');
    }

    public static function ownTrack(): self
    {
        return new self(
            'Na swój kawałek nie zagłosujesz — poproś znajomych!',
            'own_track'
        );
    }

    public static function guestBanned(): self
    {
        return new self('Organizator wstrzymał Twój dostęp.', 'banned');
    }

    public static function partyClosed(): self
    {
        return new self('Impreza jest zamknięta — nie można już nic dodawać.', 'closed');
    }
}
