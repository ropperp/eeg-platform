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

/** Erlaubte Breiten eines Kastens am Laptop, in Spalten eines 12er-Rasters (12 = volle Breite). */
function dashboardSpans(): array
{
    return [4, 6, 8, 12];
}

/** Prüft/bereinigt eine vom Browser geschickte Anordnung (nur bekannte Kennungen, keine
 *  Duplikate, Breiten nur aus dashboardSpans()). */
function dashboardSanitizeLayout(array $input): array
{
    $validId = fn($id) => is_string($id) && preg_match('/^[a-z0-9-]{1,40}$/', $id);
    $clean = fn($list) => array_values(array_unique(array_filter((array)$list, $validId)));
    $sizes = [];
    foreach ((array)($input['sizes'] ?? []) as $id => $span) {
        if ($validId($id) && in_array((int)$span, dashboardSpans(), true) && (int)$span !== 12 && count($sizes) < 50) {
            $sizes[$id] = (int)$span;
        }
    }
    // Höhe am Laptop in Pixeln (Mindesthöhe des Kastens; kleiner als sein Inhalt wird er nie,
    // das begrenzt schon der Browser beim Ziehen). 0/fehlend = automatisch.
    $heights = [];
    foreach ((array)($input['heights'] ?? []) as $id => $px) {
        $px = (int)$px;
        if ($validId($id) && $px >= 60 && $px <= 2000 && count($heights) < 50) {
            $heights[$id] = $px;
        }
    }
    return ['order' => array_slice($clean($input['order'] ?? []), 0, 50),
            'hidden' => array_slice($clean($input['hidden'] ?? []), 0, 50),
            'sizes' => $sizes, 'heights' => $heights];
}

/**
 * Laptop und Handy getrennt (Patrick, 14.11.2026: "speichere die Reihenfolge der Kacheln für
 * Laptop und Handy getrennt"). Gespeichert als {"desktop": {...}, "mobile": {...}}; eine ältere,
 * gerätelose Anordnung ({"order", "hidden"}) gilt für beide, bis eines davon neu gespeichert wird.
 * Liefert ['desktop' => [...], 'mobile' => [...]] jeweils mit order/hidden (+ sizes am Laptop).
 */
function dashboardDeviceLayouts(array $ids, ?array $saved): array
{
    $legacy = $saved && !isset($saved['desktop']) && !isset($saved['mobile']) ? $saved : null;
    $desktopSaved = $saved['desktop'] ?? $legacy;
    $mobileSaved = $saved['mobile'] ?? $legacy;
    $desktop = dashboardMergeLayout($ids, $desktopSaved);
    $desktop['sizes'] = [];
    foreach ((array)($desktopSaved['sizes'] ?? []) as $id => $span) {
        if (in_array($id, $ids, true) && in_array((int)$span, dashboardSpans(), true)) {
            $desktop['sizes'][$id] = (int)$span;
        }
    }
    $desktop['heights'] = [];
    foreach ((array)($desktopSaved['heights'] ?? []) as $id => $px) {
        if (in_array($id, $ids, true) && (int)$px >= 60 && (int)$px <= 2000) {
            $desktop['heights'][$id] = (int)$px;
        }
    }
    return ['desktop' => $desktop, 'mobile' => dashboardMergeLayout($ids, $mobileSaved)];
}

/** Speichert die Anordnung EINES Geräts in die bestehende (ggf. noch gerätelose) Anordnung. */
function dashboardStoreDevice(?array $saved, string $device, ?array $layout): ?array
{
    $saved = $saved ?: [];
    if (!isset($saved['desktop']) && !isset($saved['mobile']) && $saved) {
        $saved = ['desktop' => $saved, 'mobile' => $saved];   // alte, gerätelose Anordnung aufteilen
    }
    if ($layout === null) {
        unset($saved[$device]);
    } else {
        if ($device === 'mobile') { unset($layout['sizes'], $layout['heights']); }
        $saved[$device] = $layout;
    }
    $saved = array_intersect_key($saved, ['desktop' => 1, 'mobile' => 1]);
    return $saved ?: null;
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
        $layouts = dashboardDeviceLayouts($ids, $this->layout);
        // Im HTML steht die Laptop-Anordnung; am Handy ordnet dashboard-widgets.js beim Laden
        // sofort nach der Handy-Anordnung um (data-layouts), bevor die Seite gezeichnet wird.
        $layout = $layouts['desktop'];
        $h = fn(string $s) => htmlspecialchars($s, ENT_QUOTES);
        // Kundenansicht ("Ins Portal des Kunden"): Anordnung des Mitglieds nur zeigen, nicht ändern
        $readOnly = class_exists('Auth', false) && method_exists('Auth', 'isImpersonating') && Auth::isImpersonating();
        $out = $readOnly ? '' : '<div class="dash-toolbar" data-dash-toolbar="' . $h($this->key) . '">'
            . '<p class="dash-edit-hint" hidden>Kästen per Ziehen oder mit den Pfeilen verschieben, mit dem Auge ausblenden<span class="dash-hint-desktop">, am rechten Rand die Breite, am unteren Rand die Höhe, an der Ecke beides ziehen (kleiner als der Inhalt geht nicht)</span>. Die Ansicht entspricht schon dem Ergebnis. Gilt nur für dieses Gerät (Laptop bzw. Handy) und wird in Ihrem Konto gespeichert.</p>'
            . '<div class="dash-hidden-tray" hidden><span class="dash-hidden-label">Ausgeblendet:</span></div>'
            . '<div class="dash-toolbar-buttons">'
            . '<button type="button" class="btn btn-secondary dash-btn-reset" hidden>Standard</button>'
            . '<button type="button" class="btn btn-primary dash-btn-edit" aria-pressed="false">'
            . icon('list') . ' <span>Anordnen</span></button>'
            . '</div>'
            . '<form method="post" action="/portal/dashboard-layout" class="dash-layout-form" hidden>'
            . '<input type="hidden" name="dashboard" value="' . $h($this->key) . '">'
            . '<input type="hidden" name="layout" value=""><input type="hidden" name="device" value="desktop"></form>'
            . '</div>';
        $out .= '<div class="dash-widgets" data-dashboard="' . $h($this->key) . '" data-layouts="'
            . $h((string)json_encode($layouts)) . '">';
        foreach ($layout['order'] as $id) {
            $w = $this->widgets[$id];
            $isHidden = in_array($id, $layout['hidden'], true);
            $span = (int)($layout['sizes'][$id] ?? 12);
            $height = (int)($layout['heights'][$id] ?? 0);
            $out .= '<section class="dash-widget' . ($isHidden ? ' is-hidden-widget' : '') . '" data-widget="' . $h($id) . '"'
                . ' data-title="' . $h($w['title']) . '"'
                . ' data-span="' . $span . '" data-height="' . $height . '"'
                . ' style="--span:' . $span . ($height ? ';--min-h:' . $height . 'px' : '') . '"'
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
                . '<button type="button" class="dash-resize dash-resize-x" data-axis="x" hidden aria-label="Breite von ' . $h($w['title'])
                . ' ändern (ziehen oder Pfeiltasten links/rechts)" title="Breite ändern: ziehen"></button>'
                . '<button type="button" class="dash-resize dash-resize-y" data-axis="y" hidden aria-label="Höhe von ' . $h($w['title'])
                . ' ändern (ziehen oder Pfeiltasten auf/ab)" title="Höhe ändern: ziehen"></button>'
                . '<button type="button" class="dash-resize dash-resize-xy" data-axis="xy" hidden tabindex="-1" aria-hidden="true"'
                . ' title="Breite und Höhe ändern: ziehen"></button>'
                . '</section>';
        }
        $out .= '</div>';
        // Auch in der Kundenansicht laden: ordnet am Handy nach der Handy-Anordnung um.
        $out .= '<script src="/assets/js/dashboard-widgets.js?v=' . (@filemtime(__DIR__ . '/../public/assets/js/dashboard-widgets.js') ?: 1) . '"></script>';
        return $out;
    }
}
