<?php

declare(strict_types=1);

namespace App\Domain\Statutes;

/**
 * Einladungen zum Statuten-Generator und ihre Zustände.
 *
 *   offen ──(Statuten erzeugen)──► erzeugt (nur Download, 90 Tage)
 *     │                                │
 *     └──────(Ablaufdatum)──► abgelaufen ◄┘        jederzeit: widerrufen (Admin)
 *
 * Der Link enthält einen Zufallstoken (256 Bit, base64url); gespeichert wird nur sein SHA-256.
 * "abgelaufen" wird aus expires_at berechnet, nicht gespeichert. Nach dem Erzeugen ist der
 * Fragebogen über den Link gesperrt; Änderungen nur über den Admin (neue Fassung oder neue
 * Einladung). Entscheidung: docs/adr/0008-statuten-generator.md.
 */
final class StatuteInviteService
{
    public const STATE_OPEN = 'open';
    public const STATE_GENERATED = 'generated';
    public const STATE_EXPIRED = 'expired';
    public const STATE_REVOKED = 'revoked';

    public const STATE_LABELS = [
        self::STATE_OPEN => 'offen', self::STATE_GENERATED => 'erzeugt',
        self::STATE_EXPIRED => 'abgelaufen', self::STATE_REVOKED => 'widerrufen',
    ];

    public const DEFAULT_VALID_DAYS = 30;
    public const DOWNLOAD_DAYS = 90;
    public const RETENTION_MONTHS = 12;

    /** @var \Closure(): \DateTimeImmutable */
    private \Closure $clock;

    public function __construct(
        private readonly StatuteInviteRepository $repo,
        private readonly StatutePdfRenderer $renderer,
        private readonly string $storageDir,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn(): \DateTimeImmutable => new \DateTimeImmutable('now');
    }

    // ─── Token ──────────────────────────────────────────────────────────────

    public static function newToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /** Nur formal gültige Token gehen an die Datenbank (43 Zeichen base64url). */
    public static function wellFormed(string $token): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9_-]{43}$/', $token);
    }

    // ─── Zustand ────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $invite */
    public function state(array $invite): string
    {
        if (($invite['status'] ?? '') === self::STATE_REVOKED) {
            return self::STATE_REVOKED;
        }
        if (new \DateTimeImmutable((string) $invite['expires_at']) <= ($this->clock)()) {
            return self::STATE_EXPIRED;
        }
        return ($invite['status'] ?? '') === self::STATE_GENERATED ? self::STATE_GENERATED : self::STATE_OPEN;
    }

    /**
     * Einladung zu einem Link-Token oder null (unbekannt/falsch geformt). Der DB-Treffer wird
     * zusätzlich zeitkonstant gegen den berechneten Hash verglichen.
     *
     * @return array<string, mixed>|null
     */
    public function resolve(string $token): ?array
    {
        if (!self::wellFormed($token)) {
            return null;
        }
        $hash = self::hashToken($token);
        $invite = $this->repo->findInviteByHash($hash);
        if ($invite === null || !hash_equals((string) $invite['token_hash'], $hash)) {
            return null;
        }
        return $invite;
    }

    // ─── Admin ──────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $prefill
     * @return array{id: string, token: string}
     */
    public function create(string $label, int $validDays, array $prefill, ?string $adminId): array
    {
        $label = trim(mb_substr($label, 0, 150));
        if ($label === '') {
            throw new StatuteException('Bitte eine Bezeichnung für den Interessenten angeben.');
        }
        $validDays = max(1, min(365, $validDays));
        $clean = [];
        foreach (StatuteQuestionnaire::PREFILL_KEYS as $k) {
            $v = trim((string) ($prefill[$k] ?? ''));
            if ($v !== '') {
                $clean[$k] = mb_substr($v, 0, 120);
            }
        }
        $token = self::newToken();
        $id = $this->repo->insertInvite([
            'token_hash' => self::hashToken($token),
            'label'      => $label,
            'prefill'    => $clean,
            'expires_at' => ($this->clock)()->modify("+{$validDays} days")->format(DATE_ATOM),
            'created_by' => $adminId,
        ]);
        return ['id' => $id, 'token' => $token];
    }

    /** Neuer Link für eine Einladung; der alte wird damit ungültig. Läuft die Frist, wird sie verlängert. */
    public function rotate(string $id, int $validDays = self::DEFAULT_VALID_DAYS): string
    {
        $invite = $this->mustFind($id);
        if ($this->state($invite) === self::STATE_REVOKED) {
            throw new StatuteException('Die Einladung ist widerrufen.');
        }
        $token = self::newToken();
        $days = $invite['status'] === self::STATE_GENERATED ? self::DOWNLOAD_DAYS : max(1, min(365, $validDays));
        $this->repo->updateInvite($id, [
            'token_hash' => self::hashToken($token),
            'expires_at' => ($this->clock)()->modify("+{$days} days")->format(DATE_ATOM),
        ]);
        return $token;
    }

    public function revoke(string $id): void
    {
        $this->mustFind($id);
        $this->repo->updateInvite($id, [
            'status' => self::STATE_REVOKED,
            'revoked_at' => ($this->clock)()->format(DATE_ATOM),
        ]);
    }

    // ─── Antworten ──────────────────────────────────────────────────────────

    /**
     * Aktueller Stand für das Formular: Arbeitsstand, sonst letzte Fassung, sonst Vorbelegung.
     *
     * @param array<string, mixed> $invite
     * @return array<string, mixed>
     */
    public function currentAnswers(array $invite): array
    {
        $draft = $this->repo->workingDraft((string) $invite['id']);
        if ($draft !== null) {
            return StatuteQuestionnaire::normalize(self::json($draft['answers']), false);
        }
        $versions = $this->repo->versions((string) $invite['id']);
        if ($versions !== []) {
            return StatuteQuestionnaire::normalize(self::json($versions[0]['answers']), false);
        }
        return StatuteQuestionnaire::withPrefill(self::json($invite['prefill'] ?? []));
    }

    /**
     * Zwischenspeichern über den Link (nur im Zustand „offen“).
     *
     * @param array<string, mixed> $invite
     * @param array<string, mixed> $input
     * @return array{answers: array<string, mixed>, errors: list<string>, hints: list<string>}
     */
    public function saveByLink(array $invite, array $input): array
    {
        $this->requireOpen($invite);
        return $this->save($invite, $input);
    }

    /**
     * Statuten über den Link erzeugen: prüft, erzeugt die PDFs (Version 1) und schaltet den Link
     * auf „nur Download“ (90 Tage).
     *
     * @param array<string, mixed> $invite
     * @param array<string, mixed> $input
     */
    public function generateByLink(array $invite, array $input): int
    {
        $this->requireOpen($invite);
        return $this->generate($invite, $input, null);
    }

    /**
     * Admin: Arbeitsstand speichern (in jedem Zustand außer widerrufen).
     *
     * @param array<string, mixed> $input
     * @return array{answers: array<string, mixed>, errors: list<string>, hints: list<string>}
     */
    public function saveByAdmin(string $id, array $input): array
    {
        $invite = $this->mustFind($id);
        $this->requireNotRevoked($invite);
        return $this->save($invite, $input);
    }

    /** @param array<string, mixed> $input */
    public function generateByAdmin(string $id, array $input, string $adminId): int
    {
        $invite = $this->mustFind($id);
        $this->requireNotRevoked($invite);
        return $this->generate($invite, $input, $adminId);
    }

    /** Admin gibt eine Fassung nach fachlicher Prüfung frei: PDFs ohne Entwurfs-Kopfzeile. */
    public function release(string $id, int $version, ?string $adminId): void
    {
        $this->mustFind($id);
        $draft = $this->repo->version($id, $version);
        if ($draft === null) {
            throw new StatuteException('Fassung nicht gefunden.');
        }
        $answers = StatuteQuestionnaire::normalize(self::json($draft['answers']), false);
        $this->writePdfs((string) $draft['pdf_path'], $answers, $version, false);
        $this->repo->markReleased((string) $draft['id'], $adminId);
    }

    // ─── Dateien ────────────────────────────────────────────────────────────

    /**
     * Pfad eines erzeugten PDFs oder null. Nur bekannte Dokumentnamen, Pfad aus der DB.
     *
     * @param array<string, mixed> $draft
     */
    public function pdfFile(array $draft, string $document): ?string
    {
        if (!array_key_exists($document, StatutePdfRenderer::DOCUMENTS) || empty($draft['pdf_path'])) {
            return null;
        }
        $file = $this->storageDir . '/' . $draft['pdf_path'] . '/' . $document . '.pdf';
        return is_file($file) ? $file : null;
    }

    /**
     * Löschkonzept: Einladungen samt Entwürfen und PDFs 12 Monate nach der letzten Erzeugung
     * (bzw. nach Ablauf/Widerruf, wenn nie erzeugt) löschen. Liefert die Anzahl.
     */
    public function purgeStale(): int
    {
        $cutoff = ($this->clock)()->modify('-' . self::RETENTION_MONTHS . ' months')->format(DATE_ATOM);
        $n = 0;
        foreach ($this->repo->staleInvites($cutoff) as $row) {
            $this->removeDir($this->storageDir . '/' . $row['id']);
            $this->repo->deleteInvite($row['id']);
            $n++;
        }
        return $n;
    }

    // ─── intern ─────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $invite
     * @param array<string, mixed> $input
     * @return array{answers: array<string, mixed>, errors: list<string>, hints: list<string>}
     */
    private function save(array $invite, array $input): array
    {
        $answers = StatuteQuestionnaire::normalize($input);
        $this->repo->saveWorkingDraft((string) $invite['id'], $answers);
        return ['answers' => $answers] + StatuteRules::check($answers, false);
    }

    /**
     * @param array<string, mixed> $invite
     * @param array<string, mixed> $input
     */
    private function generate(array $invite, array $input, ?string $adminId): int
    {
        $answers = StatuteQuestionnaire::normalize($input);
        $this->repo->saveWorkingDraft((string) $invite['id'], $answers);
        $check = StatuteRules::check($answers, true);
        if ($check['errors'] !== []) {
            throw new StatuteException('Bitte die markierten Punkte korrigieren.', $check['errors']);
        }
        $versions = $this->repo->versions((string) $invite['id']);
        $version = $versions === [] ? 1 : ((int) $versions[0]['version']) + 1;
        $relDir = $invite['id'] . '/v' . $version;
        $this->writePdfs($relDir, $answers, $version, true);
        $this->repo->insertVersion((string) $invite['id'], $version, $answers, $relDir, $adminId);

        $now = ($this->clock)();
        $fields = [
            'expires_at' => $now->modify('+' . self::DOWNLOAD_DAYS . ' days')->format(DATE_ATOM),
        ];
        if ($invite['status'] !== self::STATE_REVOKED) {
            $fields['status'] = self::STATE_GENERATED;
        }
        if ($adminId === null && empty($invite['used_at'])) {
            $fields['used_at'] = $now->format(DATE_ATOM);
        }
        $this->repo->updateInvite((string) $invite['id'], $fields);
        return $version;
    }

    /** @param array<string, mixed> $answers */
    private function writePdfs(string $relDir, array $answers, int $version, bool $draft): void
    {
        $dir = $this->storageDir . '/' . $relDir;
        if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new \RuntimeException('Speicherordner für Statuten nicht beschreibbar: ' . $dir);
        }
        foreach (array_keys(StatutePdfRenderer::DOCUMENTS) as $doc) {
            $pdf = $this->renderer->render($doc, $answers, $version, $draft);
            if (file_put_contents($dir . '/' . $doc . '.pdf', $pdf) === false) {
                throw new \RuntimeException('PDF konnte nicht gespeichert werden: ' . $doc);
            }
        }
    }

    /** @param array<string, mixed> $invite */
    private function requireOpen(array $invite): void
    {
        if ($this->state($invite) !== self::STATE_OPEN) {
            throw new StatuteException('Der Fragebogen ist über diesen Link nicht mehr bearbeitbar.');
        }
    }

    /** @param array<string, mixed> $invite */
    private function requireNotRevoked(array $invite): void
    {
        if (($invite['status'] ?? '') === self::STATE_REVOKED) {
            throw new StatuteException('Die Einladung ist widerrufen.');
        }
    }

    /** @return array<string, mixed> */
    private function mustFind(string $id): array
    {
        $invite = $this->repo->findInvite($id);
        if ($invite === null) {
            throw new StatuteException('Einladung nicht gefunden.');
        }
        return $invite;
    }

    /** @return array<string, mixed> */
    private static function json(mixed $v): array
    {
        if (is_array($v)) {
            return $v;
        }
        $d = json_decode((string) $v, true);
        return is_array($d) ? $d : [];
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir) || !str_starts_with(realpath($dir) ?: '', realpath($this->storageDir) ?: "\0")) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }
}
