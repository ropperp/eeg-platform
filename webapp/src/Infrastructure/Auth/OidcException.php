<?php

declare(strict_types=1);

namespace App\Infrastructure\Auth;

/** Fehler bei der Anmeldung über Keycloak. getMessage() ist für Benutzer gedacht, $code fürs Log. */
final class OidcException extends \RuntimeException
{
    public function __construct(string $message, public readonly string $reason = '')
    {
        parent::__construct($message);
    }
}
