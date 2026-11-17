<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Assembly\AssemblyException;
use App\Domain\Assembly\AssemblyRepository;
use App\Domain\Assembly\AssemblyService;
use App\Http\Responses\HtmlResponse;
use App\Http\Responses\PdfResponse;
use App\Http\Responses\RedirectResponse;
use App\Http\Responses\Response;

/**
 * Protokolle von Mitgliederversammlungen.
 *  - Obmann/Manager (aktive EEG): /portal/versammlungen…  (anlegen, bearbeiten, unterschreiben lassen)
 *  - Mitglied: /portal/my/versammlungen/:id/pdf            (nur abgeschlossen + freigegeben)
 *  - Unterzeichner ohne Login: /portal/unterschrift/versammlung/:token
 */
final class AssemblyController
{
    private const BASE = '/portal/versammlungen';

    public function __construct(
        private readonly AssemblyRepository $repo,
        private readonly AssemblyService $service,
    ) {
    }

    // ─── Obmann/Manager ─────────────────────────────────────────────────────

    /** @param array<string, mixed> $query */
    public function index(array $query): Response
    {
        $communityId = self::manager();
        return new HtmlResponse('pages/assemblies', [
            'assemblies' => $this->repo->forCommunity($communityId),
            'error'      => self::str($query['error'] ?? ''),
            'success'    => self::str($query['success'] ?? ''),
        ]);
    }

    /** @param array<string, mixed> $post */
    public function create(array $post): Response
    {
        $communityId = self::manager();
        try {
            $id = $this->service->create($communityId, \Auth::userId(), $post);
        } catch (AssemblyException $e) {
            return self::back(self::BASE, $e);
        }
        \logAudit($communityId, 'assembly.create', 'assembly', $id, 'Protokoll Mitgliederversammlung angelegt');
        return new RedirectResponse(self::BASE . '/' . $id);
    }

    /**
     * @param array<string, mixed> $post
     * @param array<string, mixed> $file Eintrag aus $_FILES
     */
    public function upload(array $post, array $file): Response
    {
        $communityId = self::manager();
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return self::back(self::BASE, new AssemblyException('Bitte eine PDF-Datei auswählen.'));
        }
        try {
            $id = $this->service->uploadFinal($communityId, \Auth::userId(), $post, (string) $file['tmp_name'], (int) $file['size']);
        } catch (AssemblyException $e) {
            return self::back(self::BASE, $e);
        }
        \logAudit($communityId, 'assembly.upload', 'assembly', $id, 'Fertiges Protokoll (PDF) hochgeladen');
        return new RedirectResponse(self::BASE . '?success=' . urlencode('Protokoll hochgeladen.'));
    }

    /** @param array<string, mixed> $query */
    public function show(string $id, array $query): Response
    {
        $communityId = self::manager();
        $assembly = $this->repo->find($id, $communityId);
        if (!$assembly) {
            return self::notFound();
        }
        return new HtmlResponse('pages/assembly_edit', [
            'a'         => $assembly,
            'items'     => $this->repo->items($id),
            'signers'   => $this->repo->signers($id),
            'deletable' => AssemblyService::isDeletable($assembly),
            'error'     => self::str($query['error'] ?? ''),
            'success'   => self::str($query['success'] ?? ''),
        ]);
    }

    /**
     * Alle Bearbeitungs-Aktionen auf einem Protokoll (POST). $action wird in der Route fest
     * vorgegeben, nie aus der URL übernommen.
     *
     * @param array<string, mixed> $post
     */
    public function act(string $id, string $action, array $post, string $subId = ''): Response
    {
        $communityId = self::manager();
        $assembly = $this->repo->find($id, $communityId);
        if (!$assembly) {
            return self::notFound();
        }
        $url = self::BASE . '/' . $assembly['id'];
        $community = self::community($communityId);
        $message = '';
        try {
            switch ($action) {
                case 'header':
                    $this->service->updateHeader($assembly, $post);
                    $message = 'Gespeichert.';
                    break;
                case 'add_item':
                    $this->service->addItem($assembly, $post);
                    $url .= '#punkte';
                    break;
                case 'update_item':
                    $this->service->updateItem($assembly, $subId, $post);
                    $url .= '#punkte';
                    break;
                case 'delete_item':
                    $this->service->deleteItem($assembly, $subId);
                    $url .= '#punkte';
                    break;
                case 'move_item':
                    $this->service->moveItem($assembly, $subId, ($post['direction'] ?? '') === 'up' ? 'up' : 'down');
                    $url .= '#punkte';
                    break;
                case 'add_signer':
                    $this->service->addSigner($assembly, $post);
                    $url .= '#unterschriften';
                    break;
                case 'delete_signer':
                    $this->service->deleteSigner($assembly, $subId);
                    $url .= '#unterschriften';
                    break;
                case 'start_signing':
                    $this->service->startSigning($assembly);
                    \logAudit($communityId, 'assembly.signing', 'assembly', $id, 'Protokoll zur Unterschrift freigegeben');
                    $message = 'Zur Unterschrift freigegeben. Der Inhalt ist jetzt gesperrt.';
                    break;
                case 'back_to_draft':
                    $this->service->backToDraft($assembly);
                    $message = 'Wieder im Entwurf.';
                    break;
                case 'send_link':
                    $this->service->sendSignLink($assembly, $subId, \AppConfig::get()->portalBaseUrl(), (string) $community['name']);
                    \logAudit($communityId, 'assembly.sign_link', 'assembly', $id, 'Unterschrifts-Link verschickt');
                    $message = 'Link zum Unterschreiben verschickt.';
                    break;
                case 'finalize':
                    $message = $this->service->finalizeIfComplete($assembly, $community)
                        ? 'Protokoll abgeschlossen.' : 'Es fehlen noch Unterschriften.';
                    break;
                case 'visibility':
                    $this->service->setVisibility($assembly, ($post['visible'] ?? '') === '1');
                    $message = 'Sichtbarkeit gespeichert.';
                    break;
                case 'delete':
                    $this->service->delete($assembly);
                    \logAudit($communityId, 'assembly.delete', 'assembly', $id, 'Protokoll gelöscht');
                    return new RedirectResponse(self::BASE . '?success=' . urlencode('Protokoll gelöscht.'));
                default:
                    return self::notFound();
            }
        } catch (AssemblyException $e) {
            return self::back($url, $e);
        } catch (\Throwable $e) {
            error_log('[assembly] ' . $action . ': ' . $e->getMessage());
            return self::back($url, new AssemblyException('Das hat nicht geklappt: ' . $e->getMessage()));
        }
        if ($message === '') {
            return new RedirectResponse($url);
        }
        [$path, $hash] = array_pad(explode('#', $url, 2), 2, '');
        return new RedirectResponse($path . '?success=' . urlencode($message) . ($hash !== '' ? '#' . $hash : ''));
    }

    public function signOnSiteForm(string $id, string $signerId): Response
    {
        $communityId = self::manager();
        $assembly = $this->repo->find($id, $communityId);
        $signer = $assembly ? $this->repo->findSigner($signerId, $id) : null;
        if (!$assembly || !$signer) {
            return self::notFound();
        }
        return new HtmlResponse('pages/assembly_sign_onsite', [
            'a'      => $assembly,
            'signer' => $signer,
            'items'  => $this->repo->items($id),
            'error'  => self::str($_GET['error'] ?? ''),
        ]);
    }

    /** @param array<string, mixed> $post */
    public function signOnSite(string $id, string $signerId, array $post): Response
    {
        $communityId = self::manager();
        $assembly = $this->repo->find($id, $communityId);
        if (!$assembly) {
            return self::notFound();
        }
        $url = self::BASE . '/' . $assembly['id'];
        try {
            $done = $this->service->signOnSite($assembly, $signerId, (string) ($post['signature_image'] ?? ''), \clientIp(), self::community($communityId));
        } catch (AssemblyException $e) {
            return self::back($url . '/unterschreiben/' . rawurlencode($signerId), $e);
        } catch (\Throwable $e) {
            // Unterschrift ist gespeichert, nur das PDF fehlt -> "Abschließen" erneut versuchen.
            error_log('[assembly] finalize: ' . $e->getMessage());
            return self::back($url, new AssemblyException('Unterschrift gespeichert, aber das PDF konnte nicht erzeugt werden. Bitte „PDF erzeugen" erneut versuchen.'));
        }
        \logAudit($communityId, 'assembly.sign', 'assembly', $id, 'Protokoll vor Ort unterschrieben');
        return new RedirectResponse($url . '?success=' . urlencode($done ? 'Alle haben unterschrieben. Das Protokoll ist abgeschlossen.' : 'Unterschrift gespeichert.') . '#unterschriften');
    }

    public function pdf(string $id): Response
    {
        $communityId = self::manager();
        $assembly = $this->repo->find($id, $communityId);
        if (!$assembly) {
            return self::notFound();
        }
        return $this->pdfFor($assembly, self::community($communityId), true);
    }

    // ─── Mitglied ───────────────────────────────────────────────────────────

    public function memberPdf(string $id): Response
    {
        \Auth::requireLogin();
        $member = \currentMemberFull();
        if (!$member) {
            return self::notFound();
        }
        \DB::setCommunity((string) $member['community_id']);
        $assembly = $this->repo->find($id, (string) $member['community_id']);
        if (!$assembly || $assembly['status'] !== 'final' || !in_array($assembly['visible_to_members'], [true, 't', '1', 1], true)) {
            return self::notFound();
        }
        return $this->pdfFor($assembly, [], false);
    }

    // ─── Unterzeichner per Link (ohne Login) ────────────────────────────────

    public function linkForm(string $token, string $error = ''): Response
    {
        $ctx = $this->linkContext($token);
        if ($ctx === null) {
            return new HtmlResponse('pages/assembly_sign_link', ['invalid' => true], 404);
        }
        return new HtmlResponse('pages/assembly_sign_link', $ctx + ['invalid' => false, 'token' => $token, 'error' => $error, 'done' => false]);
    }

    /** @param array<string, mixed> $post */
    public function linkSign(string $token, array $post): Response
    {
        $link = $this->service->resolveLink($token);
        if ($link === null) {
            return new HtmlResponse('pages/assembly_sign_link', ['invalid' => true], 404);
        }
        \DB::setCommunity($link['community_id']);
        try {
            $this->service->signByLink($link, (string) ($post['signature_image'] ?? ''), \clientIp(), self::community($link['community_id']));
        } catch (AssemblyException $e) {
            return $this->linkForm($token, $e->getMessage());
        } catch (\Throwable $e) {
            error_log('[assembly] sign via link: ' . $e->getMessage());
            // Nur wenn die Unterschrift trotzdem gespeichert ist (PDF scheiterte), gilt das als
            // erledigt -- der Obmann schließt dann über "PDF erzeugen" ab.
            $signer = $this->repo->findSigner($link['signer_id'], $link['assembly_id']);
            if (!$signer || $signer['signed_at'] === null) {
                return $this->linkForm($token, 'Die Unterschrift konnte nicht gespeichert werden. Bitte erneut versuchen.');
            }
        }
        \logAudit($link['community_id'], 'assembly.sign', 'assembly', $link['assembly_id'], 'Protokoll per Link unterschrieben');
        return new HtmlResponse('pages/assembly_sign_link', ['invalid' => false, 'done' => true]);
    }

    public function linkPreview(string $token): Response
    {
        $ctx = $this->linkContext($token);
        if ($ctx === null) {
            return self::notFound();
        }
        return PdfResponse::fromBytes($this->service->preview($ctx['a'], $ctx['community']), 'Protokoll-Entwurf.pdf');
    }

    // ─── intern ─────────────────────────────────────────────────────────────

    /** @return array{a: array<string, mixed>, signer: array<string, mixed>, items: list<array<string, mixed>>, community: array<string, mixed>}|null */
    private function linkContext(string $token): ?array
    {
        $link = $this->service->resolveLink($token);
        if ($link === null) {
            return null;
        }
        \DB::setCommunity($link['community_id']);
        $assembly = $this->repo->find($link['assembly_id'], $link['community_id']);
        $signer = $assembly ? $this->repo->findSigner($link['signer_id'], $link['assembly_id']) : null;
        if (!$assembly || !$signer) {
            return null;
        }
        return [
            'a'         => $assembly,
            'signer'    => $signer,
            'items'     => $this->repo->items($link['assembly_id']),
            'community' => self::community($link['community_id']),
        ];
    }

    /**
     * @param array<string, mixed> $assembly
     * @param array<string, mixed> $community
     */
    private function pdfFor(array $assembly, array $community, bool $allowPreview): Response
    {
        $name = 'Protokoll_' . $assembly['held_on'] . '.pdf';
        $file = $this->service->pdfFile($assembly);
        if ($file !== null) {
            return PdfResponse::fromFile($file, $name);
        }
        if (!$allowPreview || $assembly['status'] === 'final') {
            return self::notFound();
        }
        try {
            return PdfResponse::fromBytes($this->service->preview($assembly, $community), 'Entwurf_' . $name);
        } catch (\Throwable $e) {
            error_log('[assembly] preview: ' . $e->getMessage());
            return HtmlResponse::text('Die Vorschau konnte nicht erzeugt werden (PDF-Dienst nicht erreichbar).', 503);
        }
    }

    private static function manager(): string
    {
        \Auth::requireLogin();
        $communityId = \Auth::requireManagerCommunity();
        \DB::setCommunity($communityId);
        return $communityId;
    }

    /** @return array<string, mixed> */
    private static function community(string $communityId): array
    {
        return \DB::fetchOne('SELECT id, name, address, zvr_number FROM communities WHERE id = ?', [$communityId]) ?? [];
    }

    private static function back(string $url, AssemblyException $e): Response
    {
        [$path, $hash] = array_pad(explode('#', $url, 2), 2, '');
        return new RedirectResponse($path . '?error=' . urlencode($e->getMessage()) . ($hash !== '' ? '#' . $hash : ''));
    }

    private static function notFound(): Response
    {
        return HtmlResponse::text('Protokoll nicht gefunden.', 404);
    }

    private static function str(mixed $v): string
    {
        return is_string($v) ? mb_substr($v, 0, 300) : '';
    }
}
