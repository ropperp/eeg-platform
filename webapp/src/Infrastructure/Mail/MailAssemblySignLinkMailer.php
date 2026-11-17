<?php

declare(strict_types=1);

namespace App\Infrastructure\Mail;

use App\Domain\Assembly\AssemblySignLinkMailer;

/**
 * Schickt den Einmal-Link zum Unterschreiben eines Versammlungsprotokolls. Fehler werden NICHT
 * verschluckt: der Obmann muss sehen, dass der Link nicht angekommen ist.
 */
final class MailAssemblySignLinkMailer implements AssemblySignLinkMailer
{
    public function send(array $signer, array $assembly, string $communityName, string $url, string $expiresAt): void
    {
        $datum = date('d.m.Y', (int) strtotime((string) $assembly['held_on']));
        $bis = date('d.m.Y', (int) strtotime($expiresAt));
        $body = '<p>Guten Tag ' . htmlspecialchars((string) $signer['name']) . ',</p>'
            . '<p>das Protokoll der Mitgliederversammlung von „' . htmlspecialchars($communityName) . '" vom '
            . $datum . ' ist fertig und wartet auf Ihre Unterschrift als <strong>'
            . htmlspecialchars((string) $signer['role_label']) . '</strong>.</p>'
            . '<p>Über den folgenden persönlichen Link können Sie das Protokoll lesen und direkt am Handy, '
            . 'Tablet oder Computer unterschreiben:</p>'
            . '<p><a href="' . htmlspecialchars($url) . '">Protokoll ansehen und unterschreiben</a></p>'
            . '<p style="color:#6b7280;font-size:13px">Der Link gilt bis ' . $bis . ' und nur für eine Unterschrift. '
            . 'Bitte nicht weiterleiten.</p>';
        \Mailer::send((string) $signer['email'], 'Bitte unterschreiben: Protokoll der Mitgliederversammlung vom ' . $datum, $body);
    }
}
