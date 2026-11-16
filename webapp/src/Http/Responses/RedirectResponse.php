<?php

declare(strict_types=1);

namespace App\Http\Responses;

final class RedirectResponse implements Response
{
    public function __construct(private readonly string $location)
    {
    }

    public function location(): string
    {
        return $this->location;
    }

    /** Beendet die Anfrage wie die bisherigen header('Location: ...'); exit;-Stellen. */
    public function send(): void
    {
        header('Location: ' . $this->location);
        exit;
    }
}
