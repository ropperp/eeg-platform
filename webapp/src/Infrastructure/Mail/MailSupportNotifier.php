<?php

declare(strict_types=1);

namespace App\Infrastructure\Mail;

use App\Domain\Support\SupportNotifier;

/**
 * Benachrichtigt die Support-Adresse (Platform-Admin -> E-Mail-Einstellungen, sonst
 * AppConfig::contactEmail) über ein neues Ticket -- rein informativ ("bitte im Portal ansehen").
 * Scheitert der Versand (z. B. Microsoft Graph nicht konfiguriert), wird das nur geloggt: ein
 * Mitglied darf nie an einer fehlgeschlagenen internen Benachrichtigung scheitern.
 */
final class MailSupportNotifier implements SupportNotifier
{
    public function ticketCreated(string $ticketId, array $member, string $subject, string $category, string $message): void
    {
        try {
            $to = \DB::fetchOne('SELECT support_notification_email FROM platform_mail_config WHERE id = 1')['support_notification_email']
                ?? \AppConfig::get()->contactEmail;
            $community = \DB::fetchOne('SELECT name FROM communities WHERE id = ?', [$member['community_id']]);
            $categoryLabel = $category === 'feature' ? 'Feature-Vorschlag' : 'Problem/Frage';
            $body = '<p>Neues Support-Ticket in „' . htmlspecialchars($community['name'] ?? '') . '":</p>'
                . '<p><strong>' . htmlspecialchars($subject) . '</strong> (' . $categoryLabel . ')<br>'
                . 'von ' . htmlspecialchars(trim($member['first_name'] . ' ' . $member['last_name']))
                . ' (' . htmlspecialchars($member['email'] ?? '') . ')</p>'
                . '<p>' . nl2br(htmlspecialchars($message)) . '</p>'
                . '<p><a href="' . htmlspecialchars(\AppConfig::get()->portalUrl('/portal/support/' . $ticketId, (string) ($_SERVER['HTTP_HOST'] ?? ''))) . '">Ticket im Portal ansehen &amp; antworten</a></p>';
            \Mailer::send($to, 'Neues Support-Ticket: ' . $subject, $body);
        } catch (\Throwable $e) {
            error_log('[support_ticket_mail] ' . $e->getMessage());
        }
    }
}
