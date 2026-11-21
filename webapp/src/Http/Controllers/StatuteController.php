<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Statutes\StatuteException;
use App\Domain\Statutes\StatuteInviteRepository;
use App\Domain\Statutes\StatuteInviteService;
use App\Domain\Statutes\StatutePdfRenderer;
use App\Domain\Statutes\StatuteRules;
use App\Http\Responses\HtmlResponse;
use App\Http\Responses\PdfResponse;
use App\Http\Responses\RedirectResponse;
use App\Http\Responses\Response;

/**
 * Statuten-Generator für Interessenten: /statuten/{token}, ohne Login.
 *
 *  - Zustand „offen“: Fragebogen, Zwischenspeichern, „Statuten erzeugen“
 *  - Zustand „erzeugt“: nur Download der PDFs (Fragebogen gesperrt)
 *  - abgelaufen/widerrufen/unbekannt: Hinweisseite
 *
 * Jede Antwort: noindex, nicht zwischenspeichern; Rate-Limit je IP (RateLimiter).
 */
final class StatuteController
{
    public function __construct(
        private readonly StatuteInviteRepository $repo,
        private readonly StatuteInviteService $service,
    ) {
    }

    public function show(string $token, string $flash = ''): Response
    {
        [$invite, $deny] = $this->open($token);
        if ($deny !== null) {
            return $deny;
        }
        $state = $this->service->state($invite);
        if ($state === StatuteInviteService::STATE_GENERATED) {
            return $this->downloadPage($invite, $token);
        }
        $answers = $this->service->currentAnswers($invite);
        $check = StatuteRules::check($answers, false);
        return new HtmlResponse('pages/statute_form', [
            'token'   => $token,
            'answers' => $answers,
            'errors'  => [],
            'hints'   => $check['hints'],
            'flash'   => $flash,
            'expires' => (string) $invite['expires_at'],
        ]);
    }

    /** @param array<string, mixed> $post */
    public function submit(string $token, array $post): Response
    {
        [$invite, $deny] = $this->open($token);
        if ($deny !== null) {
            return $deny;
        }
        $action = (string) ($post['aktion'] ?? 'speichern');
        try {
            if ($action === 'erzeugen') {
                $version = $this->service->generateByLink($invite, $post);
                \logAudit(null, 'statute.generate', 'statute_invite', (string) $invite['id'], "Statuten Fassung {$version} über den Einladungslink erzeugt");
                return new RedirectResponse('/statuten/' . rawurlencode($token));
            }
            $result = $this->service->saveByLink($invite, $post);
            return new HtmlResponse('pages/statute_form', [
                'token'   => $token,
                'answers' => $result['answers'],
                'errors'  => $result['errors'],
                'hints'   => $result['hints'],
                'flash'   => 'Zwischengespeichert. Sie können später über denselben Link weitermachen.',
                'expires' => (string) $invite['expires_at'],
            ]);
        } catch (StatuteException $e) {
            if ($this->service->state($invite) !== StatuteInviteService::STATE_OPEN) {
                return $this->invalid($this->service->state($invite));
            }
            $answers = $this->service->currentAnswers($invite);
            return new HtmlResponse('pages/statute_form', [
                'token'   => $token,
                'answers' => $answers,
                'errors'  => $e->errors !== [] ? $e->errors : [$e->getMessage()],
                'hints'   => StatuteRules::check($answers, false)['hints'],
                'flash'   => '',
                'expires' => (string) $invite['expires_at'],
            ], 422);
        } catch (\Throwable $e) {
            error_log('[statute] generate: ' . $e->getMessage());
            return $this->show($token, 'Die Statuten konnten gerade nicht erzeugt werden. Ihre Angaben sind gespeichert -- bitte später erneut versuchen.');
        }
    }

    public function pdf(string $token, string $document): Response
    {
        [$invite, $deny] = $this->open($token);
        if ($deny !== null) {
            return $deny;
        }
        if ($this->service->state($invite) !== StatuteInviteService::STATE_GENERATED) {
            return $this->invalid('missing');
        }
        $versions = $this->repo->versions((string) $invite['id']);
        $file = $versions !== [] ? $this->service->pdfFile($versions[0], $document) : null;
        if ($file === null) {
            return $this->invalid('missing');
        }
        return PdfResponse::fromFile($file, self::fileName($document, (int) $versions[0]['version']));
    }

    // ─── intern ─────────────────────────────────────────────────────────────

    /**
     * Gemeinsamer Einstieg: Header, Rate-Limit, Token auflösen, Zustand prüfen.
     *
     * @return array{0: array<string, mixed>, 1: ?Response}
     */
    private function open(string $token): array
    {
        if (!headers_sent()) {
            header('X-Robots-Tag: noindex, nofollow');
            header('Cache-Control: private, no-store');
        }
        if (\RateLimiter::isStatuteBlocked()) {
            return [[], $this->invalid('ratelimit')];
        }
        $invite = $this->service->resolve($token);
        if ($invite === null) {
            \RateLimiter::registerStatuteMiss();
            return [[], $this->invalid('unknown')];
        }
        $state = $this->service->state($invite);
        if ($state === StatuteInviteService::STATE_EXPIRED || $state === StatuteInviteService::STATE_REVOKED) {
            return [$invite, $this->invalid($state)];
        }
        return [$invite, null];
    }

    /** @param array<string, mixed> $invite */
    private function downloadPage(array $invite, string $token): Response
    {
        $versions = $this->repo->versions((string) $invite['id']);
        $latest = $versions[0] ?? null;
        $docs = [];
        foreach (StatutePdfRenderer::DOCUMENTS as $key => $label) {
            if ($latest !== null && $this->service->pdfFile($latest, $key) !== null) {
                $docs[$key] = $label;
            }
        }
        return new HtmlResponse('pages/statute_download', [
            'token'    => $token,
            'version'  => $latest,
            'docs'     => $docs,
            'name'     => (string) (json_decode((string) ($latest['answers'] ?? '{}'), true)['name'] ?? ''),
            'expires'  => (string) $invite['expires_at'],
        ]);
    }

    private function invalid(string $reason): Response
    {
        $status = match ($reason) {
            'ratelimit' => 429,
            'unknown', 'missing' => 404,
            default => 410,
        };
        return new HtmlResponse('pages/statute_invalid', ['reason' => $reason], $status);
    }

    public static function fileName(string $document, int $version): string
    {
        return match ($document) {
            'anzeige'   => "Checkliste-Vereinsanzeige-v{$version}.pdf",
            'protokoll' => "Protokollvorlage-Gruendungsversammlung-v{$version}.pdf",
            default     => "Statuten-v{$version}.pdf",
        };
    }
}
