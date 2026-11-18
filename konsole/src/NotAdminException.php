<?php

declare(strict_types=1);

namespace Konsole;

/** Anmeldung bei Keycloak geklappt, aber ohne Rolle platform_admin. */
final class NotAdminException extends \RuntimeException
{
}
