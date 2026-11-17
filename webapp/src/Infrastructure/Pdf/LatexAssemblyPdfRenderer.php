<?php

declare(strict_types=1);

namespace App\Infrastructure\Pdf;

use App\Domain\Assembly\AssemblyPdfRenderer;
use App\Domain\Assembly\AssemblyService;

/**
 * Protokoll-PDF über den latex-service (Vorlage latex-service/templates/protokoll.tex mit dem
 * gemeinsamen Briefkopf briefkopf.tex). Alle Freitexte werden hier escaped und als RAW_-Variablen
 * übergeben, weil sie in fertigem LaTeX (Listen, Abschnitte, Tabellenzeilen) landen.
 */
final class LatexAssemblyPdfRenderer implements AssemblyPdfRenderer
{
    public function render(array $assembly, array $items, array $signers, array $community, bool $draft): string
    {
        $assets = self::brandAsset();
        $vars = self::vars($assembly, $items, $signers, $community, $draft, $assets);
        $error = null;
        $pdf = \generateLatexPdf('protokoll', $vars, $assets, $error);
        if ($pdf === null) {
            throw new \RuntimeException($error ?? 'PDF-Erzeugung fehlgeschlagen.');
        }
        return $pdf;
    }

    /**
     * Template-Variablen (öffentlich für Tests). Fügt die Unterschriften-Bilder zu $assets hinzu.
     *
     * @param array<string, mixed>       $assembly
     * @param list<array<string, mixed>> $items
     * @param list<array<string, mixed>> $signers
     * @param array<string, mixed>       $community
     * @param array<string, string>      $assets
     * @return array<string, string>
     */
    public static function vars(array $assembly, array $items, array $signers, array $community, bool $draft, array &$assets): array
    {
        $kopf = [];
        $row = function (string $label, mixed $value) use (&$kopf): void {
            if ($value !== null && $value !== '') {
                $kopf[] = \texEscape($label) . ' & ' . \texEscape((string) $value) . ' \\\\';
            }
        };
        $zeit = trim(($assembly['time_from'] ?? '') . (!empty($assembly['time_to']) ? ' bis ' . $assembly['time_to'] : ''));
        $row('Art', AssemblyService::KINDS[$assembly['kind'] ?? 'ordentlich'] ?? '');
        $row('Datum', self::date((string) $assembly['held_on']));
        $row('Uhrzeit', $zeit !== '' ? $zeit . ' Uhr' : '');
        $row('Ort', $assembly['location'] ?? '');
        $row('Anwesende Mitglieder', $assembly['attendees_count'] ?? '');
        $row('davon stimmberechtigt', $assembly['voting_count'] ?? '');
        $row('Vorsitz', $assembly['chair_name'] ?? '');
        $row('Protokollführung', $assembly['minutes_taker'] ?? '');
        $quorum = $assembly['quorum'] ?? null;
        if ($quorum !== null) {
            $row('Beschlussfähigkeit', in_array($quorum, [true, 't', '1', 1], true) ? 'gegeben' : 'nicht gegeben');
        }

        $toc = [];
        $punkte = [];
        foreach ($items as $i => $item) {
            $n = $i + 1;
            $title = \texEscape((string) $item['title']);
            $toc[] = '\\item ' . $title;
            $p = "\\needspace{5\\baselineskip}\n{\\bfseries\\color{navy}TOP {$n}: {$title}}\\par\n";
            if (!empty($item['body'])) {
                $p .= self::paragraphs((string) $item['body']) . "\\par\n";
            }
            if (!empty($item['resolution']) || $item['votes_yes'] !== null || $item['votes_no'] !== null || $item['votes_abstain'] !== null) {
                $p .= "\\vspace{1mm}\\noindent\\colorbox{cardgray}{\\parbox{\\dimexpr\\textwidth-2\\fboxsep\\relax}{";
                if (!empty($item['resolution'])) {
                    $p .= '\\textbf{Beschluss:} ' . self::paragraphs((string) $item['resolution'], true);
                }
                $votes = [];
                foreach (['votes_yes' => 'dafür', 'votes_no' => 'dagegen', 'votes_abstain' => 'Enthaltungen'] as $k => $label) {
                    if ($item[$k] !== null && $item[$k] !== '') {
                        $votes[] = (int) $item[$k] . ' ' . $label;
                    }
                }
                if ($votes) {
                    $p .= (!empty($item['resolution']) ? '\\\\[1mm]' : '') . '{\\small\\color{midgray}Abstimmung: ' . \texEscape(implode(', ', $votes)) . '}';
                }
                $p .= "}}\\par\n";
            }
            $punkte[] = $p . "\\vspace{3mm}\n";
        }

        $sigs = [];
        foreach ($signers as $i => $s) {
            $img = '';
            if (!$draft && !empty($s['signature_image'])) {
                $file = 'unterschrift_' . ($i + 1) . '.png';
                $assets[$file] = (string) $s['signature_image'];
                $img = '\\includegraphics[height=1.3cm]{' . $file . '}';
            }
            $when = !$draft && !empty($s['signed_at']) ? self::date((string) $s['signed_at']) : '';
            $sigs[] = '\\begin{minipage}[b]{0.46\\textwidth}' . "\n"
                . '\\vspace{0pt}\\rule{0pt}{1.4cm}' . $img . "\\\\[-1mm]\n"
                . '{\\color{midgray}\\rule{\\linewidth}{0.5pt}}\\\\' . "\n"
                . '\\textbf{' . \texEscape((string) $s['name']) . '}\\\\' . "\n"
                . '{\\small\\color{midgray}' . \texEscape((string) $s['role_label'])
                . ($when !== '' ? ' $\\cdot$ unterschrieben am ' . $when : '') . '}' . "\n"
                . '\\end{minipage}';
        }
        // Zwei Unterschriften nebeneinander, dann neue Zeile.
        $sigRows = [];
        foreach (array_chunk($sigs, 2) as $pair) {
            $sigRows[] = '\\noindent ' . implode("\\hfill\n", $pair) . "\\par\\vspace{8mm}";
        }

        return [
            'EEG_NAME'          => (string) ($community['name'] ?? ''),
            'EEG_ADRESSE'       => (string) ($community['address'] ?? ''),
            'EEG_ZVR'           => (string) ($community['zvr_number'] ?? ''),
            'TITEL'             => (string) $assembly['title'],
            'DATUM'             => self::date((string) $assembly['held_on']),
            'RAW_ENTWURF'       => $draft ? 'ENTWURF -- noch nicht von allen unterschrieben' : '',
            'RAW_KOPFDATEN'     => implode("\n", $kopf),
            'RAW_TAGESORDNUNG'  => $toc ? implode("\n", $toc) : '\\item (keine Punkte)',
            'RAW_PUNKTE'        => implode("\n", $punkte),
            'RAW_NOTIZEN'       => !empty($assembly['notes']) ? self::paragraphs((string) $assembly['notes']) : '',
            'RAW_UNTERSCHRIFTEN' => implode("\n", $sigRows),
        ];
    }

    /**
     * Freitext → LaTeX: Leerzeile = neuer Absatz, einfacher Zeilenumbruch bleibt erhalten.
     * $inline: für Makro-Argumente (z. B. in \\colorbox), die keine Leerzeile/\\par vertragen --
     * Absätze werden dort durch einen doppelten Zeilenumbruch dargestellt.
     */
    public static function paragraphs(string $text, bool $inline = false): string
    {
        $paras = preg_split('/\n\s*\n/', trim(str_replace("\r\n", "\n", $text))) ?: [];
        $out = [];
        foreach ($paras as $para) {
            $lines = array_map(fn($l) => \texEscape(trim($l)), explode("\n", trim($para)));
            $out[] = implode('\\newline ', array_filter($lines, fn($l) => $l !== ''));
        }
        return implode($inline ? '\\newline\\newline ' : "\n\n", array_filter($out, fn($p) => $p !== ''));
    }

    private static function date(string $value): string
    {
        $ts = strtotime($value);
        return $ts ? date('d.m.Y', $ts) : $value;
    }

    /** "Strom für alle"-Logo für den Briefkopf (hochgeladenes Website-Logo, sonst Standard). */
    private static function brandAsset(): array
    {
        $path = (function_exists('adminFilePath') ? \adminFilePath('logo-light.png') : null)
            ?? ROOT . '/public/assets/images/logo.png';
        return is_file($path) ? ['brand.png' => base64_encode((string) file_get_contents($path))] : [];
    }
}
