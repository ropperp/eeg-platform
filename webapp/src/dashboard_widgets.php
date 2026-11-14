<?php

declare(strict_types=1);

// Selbst anordnenbare Startseiten-Kästen ("Widgets") im Admin-, Obmann- und Mitgliederbereich
// (Patrick, 14.11.2026: "dass jeder selber seine Kästen [...] selbst anordnen kann [...] am besten
// im Account gespeichert"). Reihenfolge und ausgeblendete Kästen liegen je Benutzer und
// Startseite in user_dashboard_layouts (migrate_20261114.sql), gespeichert über
// POST /portal/dashboard-layout. Bedienung: assets/js/dashboard-widgets.js, Optik: app.css.
//
// Verwendung in einer Seite:
//   $dw = new DashboardWidgets('member', dashboardLayout('member'));
//   $dw->start('rechnung', 'Letzte Rechnung'); ...HTML... $dw->end();
//   echo $dw->render();
// Ein Kasten ohne Inhalt (z.B. weil eine Bedingung darin nicht zutrifft) wird übersprungen.

/**
 * Reihenfolge der Kästen: gespeicherte Reihenfolge zuerst (nur Kästen, die es noch gibt),
 * danach neue/bisher unbekannte in ihrer Standard-Reihenfolge -- so tauchen neu eingebaute
 * Kästen bei allen automatisch auf, ohne gespeicherte Anordnungen zu zerstören.
 * Liefert ['order' => [...ids], 'hidden' => [...ids]].
 */
function dashboardMergeLayout(array $defaultIds, ?array $saved): array
{
    $savedOrder = array_values(array_filter((array)($saved['order'] ?? []), 'is_string'));
    $order = array_values(array_unique(array_merge(array_intersect($savedOrder, $defaultIds), $defaultIds)));
    $hidden = array_values(array_intersect(array_filter((array)($saved['hidden'] ?? []), 'is_string'), $defaultIds));
    return ['order' => $order, 'hidden' => $hidden];
}

/** Prüft/bereinigt eine vom Browser geschickte Anordnung (nur bekannte Kennungen, keine Duplikate). */
function dashboardSanitizeLayout(array $input): array
{
    $clean = fn($list) => array_values(array_unique(array_filter((array)$list,
        fn($id) => is_string($id) && preg_match('/^[a-z0-9-]{1,40}$/', $id))));
    return ['order' => array_slice($clean($input['order'] ?? []), 0, 50),
            'hidden' => array_slice($clean($input['hidden'] ?? []), 0, 50)];
}

/** Erlaubte Startseiten-Kennungen. */
function dashboardKeys(): array
{
    return ['admin', 'manager', 'member'];
}

final class DashboardWidgets
{
    /** @var array<string, array{title: string, html: string}> */
    private array $widgets = [];
    private ?string $current = null;
    private string $currentTitle = '';

    public function __construct(private string $key, private ?array $layout)
    {
    }

    public function start(string $id, string $title): void
    {
        $this->current = $id;
        $this->currentTitle = $title;
        ob_start();
    }

    public function end(): void
    {
        $html = (string)ob_get_clean();
        if ($this->current !== null && trim($html) !== '') {
            $this->widgets[$this->current] = ['title' => $this->currentTitle, 'html' => $html];
        }
        $this->current = null;
    }

    public function render(): string
    {
        $ids = array_keys($this->widgets);
        $layout = dashboardMergeLayout($ids, $this->layout);
        $h = fn(string $s) => htmlspecialchars($s, ENT_QUOTES);
        $out = '<div class="dash-toolbar" data-dash-toolbar="' . $h($this->key) . '">'
            . '<p class="dash-edit-hint" hidden>Kästen per Ziehen oder mit den Pfeilen verschieben, mit dem Auge aus- und einblenden. Wird in Ihrem Konto gespeichert.</p>'
            . '<div class="dash-toolbar-buttons">'
            . '<button type="button" class="btn btn-secondary dash-btn-reset" hidden>Standard</button>'
            . '<button type="button" class="btn btn-primary dash-btn-edit" aria-pressed="false">'
            . icon('list') . ' <span>Anordnen</span></button>'
            . '</div>'
            . '<form method="post" action="/portal/dashboard-layout" class="dash-layout-form" hidden>'
            . '<input type="hidden" name="dashboard" value="' . $h($this->key) . '">'
            . '<input type="hidden" name="layout" value=""></form>'
            . '</div>';
        $out .= '<div class="dash-widgets" data-dashboard="' . $h($this->key) . '">';
        foreach ($layout['order'] as $id) {
            $w = $this->widgets[$id];
            $isHidden = in_array($id, $layout['hidden'], true);
            $out .= '<section class="dash-widget' . ($isHidden ? ' is-hidden-widget' : '') . '" data-widget="' . $h($id) . '"'
                . ' aria-label="' . $h($w['title']) . '">'
                . '<div class="dash-widget-bar" hidden>'
                . '<span class="dash-handle" aria-hidden="true">⋮⋮</span>'
                . '<strong>' . $h($w['title']) . '</strong>'
                . '<span class="dash-widget-actions">'
                . '<button type="button" class="dash-move" data-dir="-1" aria-label="' . $h($w['title']) . ' nach oben">↑</button>'
                . '<button type="button" class="dash-move" data-dir="1" aria-label="' . $h($w['title']) . ' nach unten">↓</button>'
                . '<button type="button" class="dash-toggle" aria-pressed="' . ($isHidden ? 'false' : 'true') . '" aria-label="' . $h($w['title']) . ' anzeigen">'
                . icon($isHidden ? 'eye-slash' : 'eye') . '</button>'
                . '</span></div>'
                . '<div class="dash-widget-body">' . $w['html'] . '</div>'
                . '</section>';
        }
        $out .= '</div>';
        $out .= '<script src="/assets/js/dashboard-widgets.js?v=' . (@filemtime(__DIR__ . '/../public/assets/js/dashboard-widgets.js') ?: 1) . '"></script>';
        return $out;
    }
}
