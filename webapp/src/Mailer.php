<?php

declare(strict_types=1);

/**
 * E-Mail-Versand über Microsoft Graph (OAuth2 Client-Credentials-Flow, Anwendungsberechtigung
 * Mail.Send). Zugangsdaten (Tenant-ID/Client-ID/Client-Secret/Absenderadresse) kommen
 * ausschließlich aus platform_mail_config (Platform-Admin-Oberfläche), NIE aus dem Repo --
 * siehe CLAUDE.md. Wirft bei jedem Fehler eine Exception statt still zu scheitern, damit
 * Aufrufer (Passwort-Reset, Erstlogin-Einladung, Test-Mail) gezielt reagieren können.
 */
class Mailer
{
    private static function config(): ?array
    {
        $cfg = DB::fetchOne('SELECT * FROM platform_mail_config WHERE id = 1');
        if (!$cfg || empty($cfg['tenant_id']) || empty($cfg['client_id']) || empty($cfg['client_secret']) || empty($cfg['sender_address'])) {
            return null;
        }
        return $cfg;
    }

    public static function isConfigured(): bool
    {
        return self::config() !== null;
    }

    private static function getAccessToken(array $cfg): string
    {
        $url = 'https://login.microsoftonline.com/' . rawurlencode($cfg['tenant_id']) . '/oauth2/v2.0/token';
        $postFields = http_build_query([
            'client_id'     => $cfg['client_id'],
            'client_secret' => $cfg['client_secret'],
            'scope'         => 'https://graph.microsoft.com/.default',
            'grant_type'    => 'client_credentials',
        ]);
        $ctx = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content'       => $postFields,
            'timeout'       => 15,
            'ignore_errors' => true,
        ]]);
        $body = @file_get_contents($url, false, $ctx);
        $code = (int)explode(' ', $http_response_header[0] ?? 'HTTP/1.1 0')[1];
        $json = json_decode((string)$body, true);
        if ($code !== 200 || empty($json['access_token'])) {
            $detail = $json['error_description'] ?? $body ?: 'keine Antwort vom Token-Endpunkt';
            throw new \RuntimeException('Microsoft-Graph-Token-Anfrage fehlgeschlagen (HTTP ' . $code . '): ' . $detail);
        }
        return $json['access_token'];
    }

    /**
     * Sendet eine HTML-E-Mail über Microsoft Graph, optional mit Datei-Anhängen. Wirft eine
     * Exception bei jedem Fehler. $attachments: Liste von ['name' => string, 'contentType' =>
     * string, 'content' => Rohbytes (wird hier base64-kodiert, nicht schon vom Aufrufer)].
     */
    public static function send(string $to, string $subject, string $htmlBody, array $attachments = []): void
    {
        $cfg = self::config();
        if (!$cfg) {
            throw new \RuntimeException('Microsoft-Graph-Mailversand ist nicht konfiguriert (Platform-Admin → E-Mail-Einstellungen).');
        }
        $token = self::getAccessToken($cfg);

        // Signatur (z.B. Kontakthinweis für Rückfragen zu Rechnungen/Verträgen) an jede
        // ausgehende Mail anhängen -- global für alle Vorlagen, konfigurierbar statt hart
        // codiert (Platform-Admin -> E-Mail-Einstellungen).
        $fullBody = $htmlBody . (!empty($cfg['signature_html']) ? '<br><br>' . $cfg['signature_html'] : '');

        // Optionales Signatur-Logo: als Inline-Anhang (Content-ID) einbetten und per <img src=
        // "cid:...">  referenzieren. Inline-CID wird von Outlook/Gmail deutlich zuverlässiger
        // angezeigt als ein data:-URI und funktioniert auch bei No-Reply-Absendern.
        // Platzierung: steht der Platzhalter {{logo}} im (Signatur-)Text, wird das Logo GENAU
        // dort eingesetzt (z.B. zwischen Grußformel und Impressum) -- sonst wie bisher am Ende.
        // Größe: signature_logo_width/-height (px, optional); ohne Angaben Standard max. 64 px hoch.
        $inlineAttachments = [];
        if (!empty($cfg['signature_logo_base64'])) {
            $cid = 'signaturelogo';
            $w = (int)($cfg['signature_logo_width'] ?? 0);
            $h = (int)($cfg['signature_logo_height'] ?? 0);
            $styleParts = [];
            if ($w > 0) $styleParts[] = 'width:' . $w . 'px';
            if ($h > 0) $styleParts[] = 'height:' . $h . 'px';
            $style = $styleParts ? implode(';', $styleParts) : 'max-height:64px';
            $logoImg = '<img src="cid:' . $cid . '" alt="" style="' . $style . '">';

            if (strpos($fullBody, '{{logo}}') !== false) {
                $fullBody = str_replace('{{logo}}', $logoImg, $fullBody);
            } else {
                $fullBody .= '<br>' . $logoImg;
            }
            $inlineAttachments[] = [
                '@odata.type'  => '#microsoft.graph.fileAttachment',
                'name'         => 'logo',
                'contentType'  => $cfg['signature_logo_type'] ?: 'image/png',
                'contentBytes' => $cfg['signature_logo_base64'],
                'isInline'     => true,
                'contentId'    => $cid,
            ];
        } else {
            // Kein Logo hinterlegt: einen evtl. vorhandenen {{logo}}-Platzhalter entfernen,
            // damit er nicht als Rohtext in der Mail steht.
            $fullBody = str_replace('{{logo}}', '', $fullBody);
        }

        $message = [
            'subject'      => $subject,
            'body'         => ['contentType' => 'HTML', 'content' => $fullBody],
            'toRecipients' => [['emailAddress' => ['address' => $to]]],
        ];
        // Absender ist oft eine unüberwachte Shared Mailbox (noreply@...) -- Antworten der
        // Kunden sollen dann an ein tatsächlich gelesenes Postfach gehen. Optional, konfigurierbar
        // über Platform-Admin -> E-Mail-Einstellungen statt hart codiert.
        if (!empty($cfg['reply_to'])) {
            $message['replyTo'] = [['emailAddress' => ['address' => $cfg['reply_to']]]];
        }
        $fileAttachments = array_map(
            fn(array $a) => [
                '@odata.type'  => '#microsoft.graph.fileAttachment',
                'name'         => $a['name'],
                'contentType'  => $a['contentType'],
                'contentBytes' => base64_encode($a['content']),
            ],
            $attachments
        );
        $allAttachments = array_merge($inlineAttachments, $fileAttachments);
        if (!empty($allAttachments)) {
            $message['attachments'] = $allAttachments;
        }

        $payload = json_encode([
            'message'          => $message,
            'saveToSentItems' => false,
        ]);

        $url = 'https://graph.microsoft.com/v1.0/users/' . rawurlencode($cfg['sender_address']) . '/sendMail';
        $ctx = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => "Content-Type: application/json\r\nAuthorization: Bearer {$token}\r\n",
            'content'       => $payload,
            'timeout'       => 15,
            'ignore_errors' => true,
        ]]);
        $body = @file_get_contents($url, false, $ctx);
        $code = (int)explode(' ', $http_response_header[0] ?? 'HTTP/1.1 0')[1];
        if ($code !== 202) {
            throw new \RuntimeException('Microsoft-Graph-Mailversand fehlgeschlagen (HTTP ' . $code . '): ' . ($body ?: 'keine Antwort'));
        }
    }
}
