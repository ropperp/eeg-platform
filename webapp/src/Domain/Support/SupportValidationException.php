<?php

declare(strict_types=1);

namespace App\Domain\Support;

/** Eingabe unvollständig; die Meldung ist für die Anzeige gedacht. */
final class SupportValidationException extends \InvalidArgumentException
{
}
