<?php

declare(strict_types=1);

namespace App\Domain\Assembly;

/**
 * Regeln für Protokolle von Mitgliederversammlungen (Tabellen: migrate_20261119.sql).
 *
 * Ablauf: draft (frei bearbeitbar) → signing (Inhalt gesperrt, Unterschriften vor Ort oder per
 * Einmal-Link) → final (alle haben unterschrieben, PDF abgelegt, unveränderlich). Solange noch
 * niemand unterschrieben hat, kann ein Protokoll von signing zurück in den Entwurf. Alternativ
 * wird ein fertiges PDF hochgeladen (source = upload, sofort final).
 */
final class AssemblyService
{
    public const KINDS = ['ordentlich' => 'Ordentliche Mitgliederversammlung', 'ausserordentlich' => 'Außerordentliche Mitgliederversammlung'];
    public const LINK_DAYS = 14;
    private const MAX_PDF_BYTES = 20 * 1024 * 1024;
    private const MAX_SIGNATURE_BYTES = 400 * 1024;

    public function __construct(
        private readonly AssemblyRepository $repo,
        private readonly AssemblyPdfRenderer $renderer,
        private readonly AssemblySignLinkMailer $mailer,
        private readonly string $storageDir,
    ) {
    }

    // ─── Anlegen und Bearbeiten (nur Entwurf) ───────────────────────────────

    /** @param array<string, mixed> $post */
    public function create(string $communityId, ?string $userId, array $post): string
    {
        return $this->repo->create($communityId, $userId, self::header($post));
    }

    /**
     * @param array<string, mixed> $assembly
     * @param array<string, mixed> $post
     */
    public function updateHeader(array $assembly, array $post): void
    {
        self::requireDraft($assembly);
        $this->repo->update((string) $assembly['id'], self::header($post));
    }

    /**
     * @param array<string, mixed> $assembly
     * @param array<string, mixed> $post
     */
    public function addItem(array $assembly, array $post): string
    {
        self::requireDraft($assembly);
        return $this->repo->addItem((string) $assembly['id'], (string) $assembly['community_id'], self::item($post));
    }

    /**
     * @param array<string, mixed> $assembly
     * @param array<string, mixed> $post
     */
    public function updateItem(array $assembly, string $itemId, array $post): void
    {
        self::requireDraft($assembly);
        $item = $this->repo->findItem($itemId, (string) $assembly['id']) ?? throw new AssemblyException('Tagesordnungspunkt nicht gefunden.');
        $this->repo->updateItem((string) $item['id'], self::item($post));
    }

    /** @param array<string, mixed> $assembly */
    public function deleteItem(array $assembly, string $itemId): void
    {
        self::requireDraft($assembly);
        $item = $this->repo->findItem($itemId, (string) $assembly['id']) ?? throw new AssemblyException('Tagesordnungspunkt nicht gefunden.');
        $this->repo->deleteItem((string) $item['id']);
    }

    /** @param array<string, mixed> $assembly */
    public function moveItem(array $assembly, string $itemId, string $direction): void
    {
        self::requireDraft($assembly);
        $items = $this->repo->items((string) $assembly['id']);
        $ids = array_map(fn($i) => (string) $i['id'], $items);
        $idx = array_search($itemId, $ids, true);
        if ($idx === false) {
            throw new AssemblyException('Tagesordnungspunkt nicht gefunden.');
        }
        $other = $direction === 'up' ? $idx - 1 : $idx + 1;
        if (!isset($ids[$other])) {
            return;
        }
        // Positionen vorher lückenlos durchnummerieren wäre nötig, wenn zwei Punkte dieselbe
        // Position hätten; addItem() vergibt aber immer max+1, Löschen erzeugt nur Lücken.
        $this->repo->swapItems($ids[$idx], $ids[$other]);
    }

    /**
     * @param array<string, mixed> $assembly
     * @param array<string, mixed> $post
     */
    public function addSigner(array $assembly, array $post): string
    {
        self::requireDraft($assembly);
        $name = self::text($post['name'] ?? '', 120);
        $role = self::text($post['role_label'] ?? '', 80);
        $email = trim((string) ($post['email'] ?? ''));
        if ($name === '' || $role === '') {
            throw new AssemblyException('Bitte Name und Funktion der unterzeichnenden Person angeben.');
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new AssemblyException('Die E-Mail-Adresse ist ungültig.');
        }
        return $this->repo->addSigner((string) $assembly['id'], (string) $assembly['community_id'], [
            'name' => $name, 'role_label' => $role, 'email' => $email !== '' ? $email : null,
        ]);
    }

    /** @param array<string, mixed> $assembly */
    public function deleteSigner(array $assembly, string $signerId): void
    {
        self::requireDraft($assembly);
        $signer = $this->repo->findSigner($signerId, (string) $assembly['id']) ?? throw new AssemblyException('Unterzeichner nicht gefunden.');
        $this->repo->deleteSigner((string) $signer['id']);
    }

    // ─── Unterschreiben ─────────────────────────────────────────────────────

    /** @param array<string, mixed> $assembly */
    public function startSigning(array $assembly): void
    {
        self::requireDraft($assembly);
        if ($this->repo->items((string) $assembly['id']) === []) {
            throw new AssemblyException('Bitte zuerst mindestens einen Tagesordnungspunkt eintragen.');
        }
        if ($this->repo->signers((string) $assembly['id']) === []) {
            throw new AssemblyException('Bitte zuerst festlegen, wer das Protokoll unterschreibt.');
        }
        $this->repo->update((string) $assembly['id'], ['status' => 'signing']);
    }

    /** @param array<string, mixed> $assembly */
    public function backToDraft(array $assembly): void
    {
        if (($assembly['status'] ?? '') !== 'signing') {
            throw new AssemblyException('Nur ein Protokoll in der Unterschriftsphase kann zurück in den Entwurf.');
        }
        foreach ($this->repo->signers((string) $assembly['id']) as $s) {
            if ($s['signed_at'] !== null) {
                throw new AssemblyException('Es wurde bereits unterschrieben. Der Inhalt kann nicht mehr geändert werden.');
            }
        }
        $this->repo->resetSignatures((string) $assembly['id']);
        $this->repo->update((string) $assembly['id'], ['status' => 'draft']);
    }

    /**
     * Unterschrift vor Ort (eingeloggter Obmann reicht das Gerät weiter).
     *
     * @param array<string, mixed> $assembly
     * @param array<string, mixed> $community
     * @return bool true, wenn damit alle unterschrieben haben und das Protokoll abgeschlossen ist
     */
    public function signOnSite(array $assembly, string $signerId, string $dataUri, string $ip, array $community): bool
    {
        self::requireSigning($assembly);
        $signer = $this->repo->findSigner($signerId, (string) $assembly['id']) ?? throw new AssemblyException('Unterzeichner nicht gefunden.');
        if ($signer['signed_at'] !== null) {
            throw new AssemblyException($signer['name'] . ' hat bereits unterschrieben.');
        }
        $this->repo->saveSignature((string) $signer['id'], self::signature($dataUri), 'vor_ort', $ip);
        return $this->finalizeIfComplete($assembly, $community);
    }

    /**
     * Erzeugt einen neuen Einmal-Link (ein älterer wird damit ungültig) und verschickt ihn.
     *
     * @param array<string, mixed> $assembly
     */
    public function sendSignLink(array $assembly, string $signerId, string $baseUrl, string $communityName): void
    {
        self::requireSigning($assembly);
        $signer = $this->repo->findSigner($signerId, (string) $assembly['id']) ?? throw new AssemblyException('Unterzeichner nicht gefunden.');
        if ($signer['signed_at'] !== null) {
            throw new AssemblyException($signer['name'] . ' hat bereits unterschrieben.');
        }
        if (empty($signer['email'])) {
            throw new AssemblyException('Für ' . $signer['name'] . ' ist keine E-Mail-Adresse hinterlegt.');
        }
        $token = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:sP', time() + self::LINK_DAYS * 86400);
        $this->repo->setSignerToken((string) $signer['id'], self::tokenHash($token), $expires);
        $this->mailer->send($signer, $assembly, $communityName, rtrim($baseUrl, '/') . '/unterschrift/versammlung/' . $token, $expires);
    }

    /**
     * Prüft einen Link und liefert die Kennungen dazu (oder null, wenn ungültig/abgelaufen/benutzt).
     *
     * @return array{signer_id: string, assembly_id: string, community_id: string}|null
     */
    public function resolveLink(string $token): ?array
    {
        if (!preg_match('/^[0-9a-f]{64}$/', $token)) {
            return null;
        }
        return $this->repo->resolveToken(self::tokenHash($token));
    }

    /**
     * Unterschrift über den Link. Der Aufrufer hat vorher resolveLink() aufgerufen und die
     * Community gesetzt (RLS).
     *
     * @param array{signer_id: string, assembly_id: string, community_id: string} $link
     * @param array<string, mixed> $community
     */
    public function signByLink(array $link, string $dataUri, string $ip, array $community): bool
    {
        $assembly = $this->repo->find($link['assembly_id'], $link['community_id']) ?? throw new AssemblyException('Protokoll nicht gefunden.');
        self::requireSigning($assembly);
        $signer = $this->repo->findSigner($link['signer_id'], (string) $assembly['id']) ?? throw new AssemblyException('Unterzeichner nicht gefunden.');
        if ($signer['signed_at'] !== null) {
            throw new AssemblyException('Sie haben dieses Protokoll bereits unterschrieben.');
        }
        $this->repo->saveSignature((string) $signer['id'], self::signature($dataUri), 'link', $ip);
        return $this->finalizeIfComplete($assembly, $community);
    }

    /**
     * Haben alle unterschrieben, wird das PDF erzeugt, abgelegt und das Protokoll abgeschlossen.
     * Scheitert das PDF (z. B. latex-service kurz weg), bleibt es in "signing" und kann über
     * denselben Aufruf erneut abgeschlossen werden -- keine Unterschrift geht verloren.
     *
     * @param array<string, mixed> $assembly
     * @param array<string, mixed> $community
     */
    public function finalizeIfComplete(array $assembly, array $community): bool
    {
        $id = (string) $assembly['id'];
        $signers = $this->repo->signers($id);
        if ($signers === [] || array_filter($signers, fn($s) => $s['signed_at'] === null) !== []) {
            return false;
        }
        $assembly = $this->repo->find($id, (string) $assembly['community_id']) ?? $assembly;
        $pdf = $this->renderer->render($assembly, $this->repo->items($id), $signers, $community, false);
        $relative = $this->store((string) $assembly['community_id'], $id, $pdf);
        $this->repo->update($id, ['status' => 'final', 'pdf_path' => $relative, 'finalized_at' => date('Y-m-d H:i:sP')]);
        return true;
    }

    // ─── Fertiges PDF hochladen, Sichtbarkeit, Löschen ──────────────────────

    /** @param array<string, mixed> $post */
    public function uploadFinal(string $communityId, ?string $userId, array $post, string $tmpPath, int $size): string
    {
        if ($size <= 0 || $size > self::MAX_PDF_BYTES || !is_file($tmpPath)) {
            throw new AssemblyException('Bitte eine PDF-Datei bis 20 MB auswählen.');
        }
        $head = (string) file_get_contents($tmpPath, false, null, 0, 5);
        if ($head !== '%PDF-') {
            throw new AssemblyException('Die Datei ist kein PDF.');
        }
        $fields = self::header($post);
        $fields['source'] = 'upload';
        $id = $this->repo->create($communityId, $userId, $fields);
        $relative = $this->store($communityId, $id, (string) file_get_contents($tmpPath));
        $this->repo->update($id, ['status' => 'final', 'pdf_path' => $relative, 'finalized_at' => date('Y-m-d H:i:sP')]);
        return $id;
    }

    /** @param array<string, mixed> $assembly */
    public function setVisibility(array $assembly, bool $visible): void
    {
        $this->repo->update((string) $assembly['id'], ['visible_to_members' => $visible]);
    }

    /**
     * Entwürfe, laufende Unterschriftsrunden und hochgeladene PDFs dürfen gelöscht werden; ein in der
     * Plattform unterschriebenes Protokoll ist ein Vereinsdokument und bleibt.
     *
     * @param array<string, mixed> $assembly
     */
    public function delete(array $assembly): void
    {
        if (!self::isDeletable($assembly)) {
            throw new AssemblyException('Ein unterschriebenes Protokoll kann nicht gelöscht werden.');
        }
        $path = $this->pdfFile($assembly);
        $this->repo->delete((string) $assembly['id']);
        if ($path !== null) {
            @unlink($path);
        }
    }

    /** @param array<string, mixed> $assembly */
    public static function isDeletable(array $assembly): bool
    {
        return ($assembly['status'] ?? '') !== 'final' || ($assembly['source'] ?? '') === 'upload';
    }

    /**
     * Vorschau (Entwurf/Unterschriftsphase) als PDF mit Hinweis "Entwurf".
     *
     * @param array<string, mixed> $assembly
     * @param array<string, mixed> $community
     */
    public function preview(array $assembly, array $community): string
    {
        $id = (string) $assembly['id'];
        return $this->renderer->render($assembly, $this->repo->items($id), $this->repo->signers($id), $community, true);
    }

    /**
     * Absoluter Pfad des abgelegten PDFs oder null.
     *
     * @param array<string, mixed> $assembly
     */
    public function pdfFile(array $assembly): ?string
    {
        $rel = (string) ($assembly['pdf_path'] ?? '');
        if ($rel === '' || str_contains($rel, '..')) {
            return null;
        }
        $path = $this->storageDir . '/' . $rel;
        return is_file($path) ? $path : null;
    }

    // ─── Eingaben prüfen ────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $post
     * @return array<string, mixed>
     */
    public static function header(array $post): array
    {
        $heldOn = trim((string) ($post['held_on'] ?? ''));
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $heldOn);
        if (!$d || $d->format('Y-m-d') !== $heldOn) {
            throw new AssemblyException('Bitte das Datum der Versammlung angeben.');
        }
        $kind = array_key_exists((string) ($post['kind'] ?? ''), self::KINDS) ? (string) $post['kind'] : 'ordentlich';
        $title = self::text($post['title'] ?? '', 160);
        return [
            'title'           => $title !== '' ? $title : self::KINDS[$kind],
            'kind'            => $kind,
            'held_on'         => $heldOn,
            'time_from'       => self::time($post['time_from'] ?? ''),
            'time_to'         => self::time($post['time_to'] ?? ''),
            'location'        => self::nullableText($post['location'] ?? '', 200),
            'attendees_count' => self::count($post['attendees_count'] ?? ''),
            'voting_count'    => self::count($post['voting_count'] ?? ''),
            'chair_name'      => self::nullableText($post['chair_name'] ?? '', 120),
            'minutes_taker'   => self::nullableText($post['minutes_taker'] ?? '', 120),
            'quorum'          => match ((string) ($post['quorum'] ?? '')) { '1' => true, '0' => false, default => null },
            'notes'           => self::nullableText($post['notes'] ?? '', 5000),
        ];
    }

    /**
     * @param array<string, mixed> $post
     * @return array<string, mixed>
     */
    public static function item(array $post): array
    {
        $title = self::text($post['title'] ?? '', 200);
        if ($title === '') {
            throw new AssemblyException('Bitte einen Titel für den Tagesordnungspunkt angeben.');
        }
        return [
            'title'         => $title,
            'body'          => self::nullableText($post['body'] ?? '', 20000),
            'resolution'    => self::nullableText($post['resolution'] ?? '', 5000),
            'votes_yes'     => self::count($post['votes_yes'] ?? ''),
            'votes_no'      => self::count($post['votes_no'] ?? ''),
            'votes_abstain' => self::count($post['votes_abstain'] ?? ''),
        ];
    }

    public static function signature(string $dataUri): string
    {
        $prefix = 'data:image/png;base64,';
        if (!str_starts_with($dataUri, $prefix) || strlen($dataUri) > self::MAX_SIGNATURE_BYTES) {
            throw new AssemblyException('Bitte im Unterschriftsfeld unterschreiben.');
        }
        $bin = base64_decode(substr($dataUri, strlen($prefix)), true);
        if ($bin === false || !str_starts_with($bin, "\x89PNG\r\n\x1a\n")) {
            throw new AssemblyException('Die Unterschrift konnte nicht gelesen werden. Bitte erneut unterschreiben.');
        }
        return $dataUri;
    }

    public static function tokenHash(string $token): string
    {
        return hash('sha256', $token);
    }

    // ─── intern ─────────────────────────────────────────────────────────────

    private function store(string $communityId, string $assemblyId, string $pdf): string
    {
        $relative = $communityId . '/' . $assemblyId . '.pdf';
        $dir = $this->storageDir . '/' . $communityId;
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Ablageordner für Protokolle nicht beschreibbar: ' . $dir);
        }
        if (file_put_contents($this->storageDir . '/' . $relative, $pdf) === false) {
            throw new \RuntimeException('Protokoll-PDF konnte nicht gespeichert werden.');
        }
        return $relative;
    }

    /** @param array<string, mixed> $assembly */
    private static function requireDraft(array $assembly): void
    {
        if (($assembly['status'] ?? '') !== 'draft') {
            throw new AssemblyException('Das Protokoll ist zur Unterschrift freigegeben und kann nicht mehr bearbeitet werden.');
        }
    }

    /** @param array<string, mixed> $assembly */
    private static function requireSigning(array $assembly): void
    {
        if (($assembly['status'] ?? '') !== 'signing') {
            throw new AssemblyException('Das Protokoll ist nicht (mehr) zur Unterschrift freigegeben.');
        }
    }

    private static function text(mixed $v, int $max): string
    {
        $s = trim(str_replace("\r\n", "\n", (string) $v));
        return mb_substr($s, 0, $max);
    }

    private static function nullableText(mixed $v, int $max): ?string
    {
        $s = self::text($v, $max);
        return $s !== '' ? $s : null;
    }

    private static function count(mixed $v): ?int
    {
        $s = trim((string) $v);
        if ($s === '') {
            return null;
        }
        if (!ctype_digit($s) || (int) $s > 100000) {
            throw new AssemblyException('Anzahlen bitte als ganze Zahl angeben.');
        }
        return (int) $s;
    }

    private static function time(mixed $v): ?string
    {
        $s = trim((string) $v);
        if ($s === '') {
            return null;
        }
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $s)) {
            throw new AssemblyException('Uhrzeiten bitte als HH:MM angeben.');
        }
        return $s;
    }
}
