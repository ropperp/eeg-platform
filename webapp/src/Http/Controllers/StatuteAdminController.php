<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Statutes\StatuteException;
use App\Domain\Statutes\StatuteInviteRepository;
use App\Domain\Statutes\StatuteInviteService;
use App\Domain\Statutes\StatuteQuestionnaire;
use App\Domain\Statutes\StatuteRules;
use App\Http\Responses\HtmlResponse;
use App\Http\Responses\PdfResponse;
use App\Http\Responses\RedirectResponse;
use App\Http\Responses\Response;

/**
 * Statuten-Einladungen im Admin-Bereich (/admin/statuten-einladungen, nur Platform-Admin): anlegen, Link
 * einmalig anzeigen bzw. neu erzeugen, widerrufen, Entwurf bearbeiten, neu erzeugen, freigeben.
 *
 * Der Link wird nur als Hash gespeichert. Direkt nach dem Anlegen bzw. „Neuer Link“ steht er
 * einmal in der Sitzung ($_SESSION['statute_new_link']) und wird auf der Detailseite angezeigt.
 */
final class StatuteAdminController
{
    public function __construct(
        private readonly StatuteInviteRepository $repo,
        private readonly StatuteInviteService $service,
    ) {
    }

    public function index(array $query): Response
    {
        if ($deny = self::guard()) {
            return $deny;
        }
        $invites = array_map(
            fn(array $i) => $i + ['state' => $this->service->state($i)],
            $this->repo->listInvites()
        );
        return new HtmlResponse('pages/admin_statutes', [
            'invites' => $invites,
            'error'   => (string) ($query['error'] ?? ''),
            'success' => (string) ($query['success'] ?? ''),
        ]);
    }

    /** @param array<string, mixed> $post */
    public function create(array $post): Response
    {
        if ($deny = self::guard()) {
            return $deny;
        }
        try {
            $r = $this->service->create(
                (string) ($post['label'] ?? ''),
                (int) ($post['valid_days'] ?? StatuteInviteService::DEFAULT_VALID_DAYS),
                [
                    'area' => (string) ($post['prefill_area'] ?? ''),
                    'grid_operator' => (string) ($post['prefill_grid_operator'] ?? ''),
                    'state' => (string) ($post['prefill_state'] ?? ''),
                ],
                \Auth::userId()
            );
        } catch (StatuteException $e) {
            return new RedirectResponse('/admin/statuten-einladungen?error=' . urlencode($e->getMessage()));
        }
        \logAudit(null, 'statute.invite', 'statute_invite', $r['id'], 'Statuten-Einladung angelegt');
        self::rememberLink($r['id'], $r['token']);
        return new RedirectResponse('/admin/statuten-einladungen/' . $r['id']);
    }

    public function show(string $id, array $query): Response
    {
        if ($deny = self::guard()) {
            return $deny;
        }
        $invite = $this->repo->findInvite($id);
        if ($invite === null) {
            return HtmlResponse::text('Einladung nicht gefunden.', 404);
        }
        $answers = $this->service->currentAnswers($invite);
        $check = StatuteRules::check($answers, true);
        $link = null;
        if (($_SESSION['statute_new_link']['id'] ?? null) === $id) {
            $link = \AppConfig::get()->marketingBaseUrl() . '/statuten/' . $_SESSION['statute_new_link']['token'];
            unset($_SESSION['statute_new_link']);
        }
        return new HtmlResponse('pages/admin_statute_detail', [
            'invite'   => $invite,
            'state'    => $this->service->state($invite),
            'versions' => $this->repo->versions($id),
            'answers'  => $answers,
            'errors'   => (array) ($_SESSION['statute_errors'][$id] ?? []),
            'hints'    => $check['hints'],
            'missing'  => $check['errors'],
            'link'     => $link,
            'error'    => (string) ($query['error'] ?? ''),
            'success'  => (string) ($query['success'] ?? ''),
            'prefill'  => json_decode((string) $invite['prefill'], true) ?: [],
        ]);
    }

    /** @param array<string, mixed> $post */
    public function act(string $id, string $action, array $post): Response
    {
        if ($deny = self::guard()) {
            return $deny;
        }
        $back = '/admin/statuten-einladungen/' . $id;
        unset($_SESSION['statute_errors'][$id]);
        try {
            switch ($action) {
                case 'widerrufen':
                    $this->service->revoke($id);
                    \logAudit(null, 'statute.revoke', 'statute_invite', $id, 'Statuten-Einladung widerrufen');
                    return new RedirectResponse($back . '?success=' . urlencode('Einladung widerrufen. Der Link funktioniert nicht mehr.'));
                case 'neuer-link':
                    $token = $this->service->rotate($id, (int) ($post['valid_days'] ?? StatuteInviteService::DEFAULT_VALID_DAYS));
                    \logAudit(null, 'statute.rotate', 'statute_invite', $id, 'Neuer Statuten-Link erzeugt (alter Link ungültig)');
                    self::rememberLink($id, $token);
                    return new RedirectResponse($back);
                case 'speichern':
                    $this->service->saveByAdmin($id, $post);
                    return new RedirectResponse($back . '?success=' . urlencode('Entwurf gespeichert.') . '#fragebogen');
                case 'erzeugen':
                    $version = $this->service->generateByAdmin($id, $post, (string) \Auth::userId());
                    \logAudit(null, 'statute.generate', 'statute_invite', $id, "Statuten Fassung {$version} vom Admin erzeugt");
                    return new RedirectResponse($back . '?success=' . urlencode("Fassung {$version} erzeugt. Der Link zeigt jetzt diese Fassung zum Download."));
                case 'freigeben':
                    $version = (int) ($post['version'] ?? 0);
                    $this->service->release($id, $version, (string) \Auth::userId());
                    \logAudit(null, 'statute.release', 'statute_invite', $id, "Statuten Fassung {$version} freigegeben (ohne Entwurfs-Kopfzeile)");
                    return new RedirectResponse($back . '?success=' . urlencode("Fassung {$version} freigegeben."));
            }
        } catch (StatuteException $e) {
            if ($e->errors !== []) {
                $_SESSION['statute_errors'][$id] = $e->errors;
                return new RedirectResponse($back . '?error=' . urlencode($e->getMessage()) . '#fragebogen');
            }
            return new RedirectResponse($back . '?error=' . urlencode($e->getMessage()));
        } catch (\Throwable $e) {
            error_log('[statute] admin ' . $action . ': ' . $e->getMessage());
            return new RedirectResponse($back . '?error=' . urlencode('Aktion fehlgeschlagen (PDF-Dienst?). Details im Log.'));
        }
        return HtmlResponse::text('Unbekannte Aktion.', 404);
    }

    public function pdf(string $id, string $version, string $document): Response
    {
        if ($deny = self::guard()) {
            return $deny;
        }
        $draft = ctype_digit($version) ? $this->repo->version($id, (int) $version) : null;
        $file = $draft !== null ? $this->service->pdfFile($draft, $document) : null;
        if ($file === null) {
            return HtmlResponse::text('PDF nicht gefunden.', 404);
        }
        return PdfResponse::fromFile($file, StatuteController::fileName($document, (int) $version));
    }

    private static function guard(): ?Response
    {
        \Auth::requireLogin();
        if (!\Auth::isPlatformAdmin()) {
            return HtmlResponse::text('Kein Zugriff', 403);
        }
        return null;
    }

    private static function rememberLink(string $id, string $token): void
    {
        $_SESSION['statute_new_link'] = ['id' => $id, 'token' => $token];
    }

    /** Für das Template: Auswahllisten der Vorbelegung. @return array<string, mixed> */
    public static function prefillOptions(): array
    {
        return [
            'areas' => StatuteQuestionnaire::AREAS,
            'states' => StatuteQuestionnaire::STATES,
            'grid_operators' => StatuteQuestionnaire::GRID_OPERATORS,
        ];
    }
}
