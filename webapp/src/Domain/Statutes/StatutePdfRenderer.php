<?php

declare(strict_types=1);

namespace App\Domain\Statutes;

/** Erzeugt die PDFs des Statuten-Generators (Umsetzung: Infrastructure\Pdf\LatexStatutePdfRenderer). */
interface StatutePdfRenderer
{
    /** Dokumente: statuten (Pflicht), anzeige (Checkliste Vereinsanzeige), protokoll (1. MV). */
    public const DOCUMENTS = [
        'statuten'  => 'Statuten',
        'anzeige'   => 'Checkliste Anzeige der Vereinserrichtung',
        'protokoll' => 'Protokollvorlage 1. Mitgliederversammlung',
    ];

    /**
     * @param array<string, mixed> $answers  normalisierte Antworten
     * @param bool $draft  true = Kopfzeile „Entwurf – keine Rechtsberatung“
     * @return string PDF-Bytes
     */
    public function render(string $document, array $answers, int $version, bool $draft): string;
}
