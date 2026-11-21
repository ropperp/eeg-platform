<?php

declare(strict_types=1);

namespace App\Domain\Statutes;

/** Fachlicher Fehler im Statuten-Generator; die Meldung(en) dürfen angezeigt werden. */
final class StatuteException extends \RuntimeException
{
    /** @param list<string> $errors */
    public function __construct(string $message, public readonly array $errors = [])
    {
        parent::__construct($message);
    }
}
