<?php

declare(strict_types=1);

namespace App\Domain\Assembly;

/** Erzeugt das Protokoll-PDF (Produktion: latex-service, Tests: Attrappe). */
interface AssemblyPdfRenderer
{
    /**
     * @param array<string, mixed>       $assembly
     * @param list<array<string, mixed>> $items
     * @param list<array<string, mixed>> $signers
     * @param array<string, mixed>       $community
     * @param bool $draft true: Vorschau mit Hinweis "Entwurf", ohne Unterschriften-Bilder
     * @return string PDF-Bytes
     * @throws \RuntimeException wenn das PDF nicht erzeugt werden konnte
     */
    public function render(array $assembly, array $items, array $signers, array $community, bool $draft): string;
}
