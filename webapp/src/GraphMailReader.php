<?php

declare(strict_types=1);

/**
 * Liest ein Postfach über Microsoft Graph (Anwendungsberechtigung Mail.Read, zusätzlich zu
 * Mail.Send bereits benötigt für Mailer.php -- gleiche App-Registrierung, gleiche Zugangsdaten
 * aus platform_mail_config, siehe Mailer::config()/getAccessToken()). Aktuell einziger Nutzer:
 * EdaAutoImporter (liest eda@stromfueralle.at aus, siehe migrate_20260825.sql).
 *
 * Mail.Read als Application Permission erlaubt der App wie schon Mail.Send Zugriff auf JEDES
 * Postfach im Tenant -- deshalb auch hier ein dediziertes Postfach verwenden, nie ein
 * persönliches (siehe docs/vorlagen/Anleitung_Mailversand_Azure_GraphAPI.md).
 */
class GraphMailReader
{
    /**
     * Ungelesene Nachrichten im Posteingang, älteste zuerst (damit ein hängengebliebener Import
     * nicht neuere Mails überholt). Liefert je Nachricht id/subject/receivedDateTime/bodyHtml/
     * hasAttachments -- noch keine Anhänge (separat über attachments() nachladen, spart eine
     * potenziell große Abfrage für Mails ohne Anhang).
     */
    public static function listUnread(string $mailbox): array
    {
        $cfg = Mailer::config();
        if (!$cfg) {
            throw new \RuntimeException('Microsoft-Graph ist nicht konfiguriert (Platform-Admin → Einstellungen).');
        }
        $token = Mailer::getAccessToken($cfg);

        $url = 'https://graph.microsoft.com/v1.0/users/' . rawurlencode($mailbox)
            . '/mailFolders/inbox/messages'
            . '?$filter=' . rawurlencode('isRead eq false')
            . '&$select=' . rawurlencode('id,subject,receivedDateTime,hasAttachments,body,from')
            . '&$orderby=' . rawurlencode('receivedDateTime asc')
            . '&$top=25';

        $json = self::get($url, $token);
        return $json['value'] ?? [];
    }

    /** Datei-Anhänge einer Nachricht (contentBytes bereits base64-dekodiert). */
    public static function attachments(string $mailbox, string $messageId): array
    {
        $cfg = Mailer::config();
        if (!$cfg) {
            throw new \RuntimeException('Microsoft-Graph ist nicht konfiguriert.');
        }
        $token = Mailer::getAccessToken($cfg);

        $url = 'https://graph.microsoft.com/v1.0/users/' . rawurlencode($mailbox)
            . '/messages/' . rawurlencode($messageId) . '/attachments';
        $json = self::get($url, $token);

        $result = [];
        foreach ($json['value'] ?? [] as $a) {
            if (($a['@odata.type'] ?? '') !== '#microsoft.graph.fileAttachment') continue;
            $result[] = [
                'name'    => $a['name'] ?? 'anhang',
                'content' => base64_decode($a['contentBytes'] ?? '', true) ?: '',
            ];
        }
        return $result;
    }

    /** Markiert eine Nachricht als gelesen -- verhindert erneute Verarbeitung beim nächsten Lauf. */
    public static function markRead(string $mailbox, string $messageId): void
    {
        $cfg = Mailer::config();
        if (!$cfg) return;
        $token = Mailer::getAccessToken($cfg);

        $url = 'https://graph.microsoft.com/v1.0/users/' . rawurlencode($mailbox)
            . '/messages/' . rawurlencode($messageId);
        $ctx = stream_context_create(['http' => [
            'method'        => 'PATCH',
            'header'        => "Content-Type: application/json\r\nAuthorization: Bearer {$token}\r\n",
            'content'       => json_encode(['isRead' => true]),
            'timeout'       => 15,
            'ignore_errors' => true,
        ]]);
        @file_get_contents($url, false, $ctx);
    }

    private static function get(string $url, string $token): array
    {
        $ctx = stream_context_create(['http' => [
            'method'        => 'GET',
            'header'        => "Authorization: Bearer {$token}\r\n",
            'timeout'       => 20,
            'ignore_errors' => true,
        ]]);
        $body = @file_get_contents($url, false, $ctx);
        $code = (int)explode(' ', $http_response_header[0] ?? 'HTTP/1.1 0')[1];
        $json = json_decode((string)$body, true);
        if ($code !== 200) {
            $detail = $json['error']['message'] ?? $body ?: 'keine Antwort';
            throw new \RuntimeException('Microsoft-Graph-Anfrage fehlgeschlagen (HTTP ' . $code . '): ' . $detail);
        }
        return $json ?? [];
    }
}
