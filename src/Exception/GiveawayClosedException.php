<?php

namespace ErnestDefoe\Giveaways\Exception;

/**
 * Thrown when an entry could not be written because the giveaway's state
 * changed under us (drawn/cancelled/window closed) — detected inside the
 * locked re-check in EntryService::enter(). Carries the translation key so
 * the API layer can surface a precise, localized message.
 */
class GiveawayClosedException extends \RuntimeException
{
    public function __construct(public readonly string $reasonKey)
    {
        parent::__construct($reasonKey);
    }
}
