# App-Parität-Backlog

Laufende Liste: alles, was auf der Web-Plattform existiert (oder sich ändert) und noch NICHT
1:1 in der App (`/api/v1/*` + iOS-Client) verfügbar ist. Patrick, 19.08.2026: "Bitte baue mir
alle Funktionen von der Plattform in die App ein [...] Alles soll eins zu eins sein" -- Ziel ist
vollständige Feature-Parität zwischen Web-Portal und App, nicht nur der bisherige
Mitglied-Selbstbedienungs-/Obmann-Grundumfang.

**Zweck dieser Datei:** Ändert sich etwas auf der Plattform (neue Web-Funktion, neue
Einstellung) oder kommt eine neue Idee dazu, wird das hier eingetragen -- unabhängig davon, ob
gerade an der App gearbeitet wird. So geht nichts verloren, bis es tatsächlich als
`/api/v1/*`-Endpunkt + App-Bildschirm nachgezogen wird. Nach dem Umsetzen: Eintrag hierher unter
"Bereits umgesetzt" verschieben (mit Datum + PR-Nummer), nicht löschen -- Verlauf bleibt
nachvollziehbar.

---

## Noch zu tun (Stand 19.08.2026)

Sortiert nach Bereich, nicht nach Priorität -- Priorität/Reihenfolge wird mit Patrick jeweils
vor dem Umsetzen abgestimmt.

### Admin (Rest, seit 19.08.2026 größtenteils erledigt -- siehe "Bereits umgesetzt")
- [ ] LaTeX-Vorlagen (`/admin/templates/*`) -- Vertrags-/Rechnungs-Vorlagen hoch-/runterladen,
      Variablen-Referenz -- bewusst zurückgestellt (Datei-/Desktop-lastig)
- [ ] Aktivitätslog-Export als Markdown (`/admin/log/export`) -- Liste selbst ist in der App
- [ ] Manueller EDA-Postfach-Import-Testlauf (`/admin/mail-settings/eda-import-run`)
- [ ] Mail-Signatur-Logo-Upload (Rest von `/admin/mail-settings` ist in der App, nur der
      Bild-Upload-Teil fehlt noch -- multipart, ähnlich Foto-Upload bei Mitgliedern)

### Obmann -- EEG-Einstellungen (aktuell nur Community-weite API-Keys in der App vorhanden)
- [ ] Stammdaten der eigenen EEG (Name, Adresse, IBAN, ZVR-Nummer, Kontakt) --
      `POST /portal/settings/community`
- [ ] Logo für Rechnungen/Verträge -- `POST /portal/settings/logo[/delete]`
- [ ] Tarifkonfiguration (Bezug/Einspeisung ct/kWh, Mitgliedsbeitrag) --
      `POST /portal/settings/tariff`
- [ ] Steuerkonfiguration (Kleinunternehmer/Standard, UID, Satz) -- `POST /portal/settings/tax`
- [ ] Unterschrift für Verträge (eigener Account) -- `POST /portal/settings/signature[/delete]`

### Obmann -- Abrechnung (komplett web-only)
- [ ] Abrechnungsläufe erstellen/Vorschau/freigeben/löschen -- `/portal/billing/*`
- [ ] Rechnungen bearbeiten, Zusatzpositionen -- `/portal/billing/:id/*`,
      `/portal/billing/invoices/:id/*`
- [ ] SEPA-XML-Export -- `/portal/billing/:id/sepa-xml`
- [ ] Mahnwesen, Rücklastschrift, als bezahlt markieren --
      `/portal/billing/invoices/:id/{mahnung,ruecklastschrift,mark-paid}`

### Obmann -- weitere Bereiche (komplett web-only)
- [ ] EDA-Import (monatliche Energiedaten-Exportdatei hochladen/verwalten) --
      `/portal/eda/upload`, `/portal/eda/imports/:id/delete`
- [ ] Beitrittsanträge prüfen/genehmigen/ablehnen -- `/portal/applications[/:id][/{approve,reject}]`
- [ ] Postfach (Systembenachrichtigungen: unbekannter Zähler, SSID-Wechsel, ...) --
      `/portal/postfach[/:id/erledigt]`
- [ ] Zählpunkt nachträglich bearbeiten/löschen/zuordnen -- aktuell nur Anlegen bei
      Mitglied-Neuanlage + Ansehen in der App, nicht nachträgliches Bearbeiten
- [ ] WLAN-Diagnoseinfos eines Zählpunkts (SSID/IP/Passwort) -- bewusst bisher NUR im Web-Portal
      (Sicherheitsentscheidung, siehe `docs/ESP_IDEEN.md` Punkt 1 -- ggf. nochmal absprechen, ob
      das auch in der App vertretbar ist)
- [ ] Vertrags-**Versand** als Obmann (Signieren als Mitglied ist in der App bereits enthalten,
      nur das Erzeugen/Versenden fehlt) -- `/portal/members/:id/contract/*`
- [ ] Mitglied deaktivieren/reaktivieren, Login löschen/zurücksetzen, Einladung erneut senden --
      `/portal/members/:id/{deactivate,reactivate,delete-login,reset-password,resend-invite}`
- [ ] Jahresübersicht eines Mitglieds -- `/portal/members/:id/jahresuebersicht[/:jahr]`

---

## Bereits umgesetzt (zur Referenz, chronologisch)

- **06.09.2026 (PR folgt):** Viertelstunden-Einspeisungsdiagramm für Einspeiser/Prosumer --
  Spiegelbild des Verbrauchsdiagramms vom 04.09.2026, diesmal `energy_direction='GENERATION'`
  aus derselben `eda_interval_data`-Tabelle. `GET /api/v1/production/interval?date=YYYY-MM-DD`
  (App) bzw. `/portal/my/einspeisung` (Web). Noch zu bauen: App-Bildschirm (analog Abschnitt 12
  in `app.md`, aber ein einzelner Wert `einspeisung_w` statt der gestapelten
  Verbrauch/Eigendeckung-Fläche).
- **04.09.2026 (PR folgt):** Viertelstunden-Verbrauchsdiagramm (Verbrauch vs. gemeinschaftliche
  Eigendeckung, ein Tag, 96 Intervalle) -- neuer EDA-Export-Typ ("Energiedaten"-Sheet, echte
  Viertelstundenwerte statt Monatssummen) über `eda-parser/parser_interval.py` importierbar,
  eigene Tabelle `eda_interval_data` (nicht abrechnungsrelevant). `GET
  /api/v1/consumption/interval?date=YYYY-MM-DD` (App) bzw. `/portal/my/verbrauch` (Web).
  Lücken-Anzeige ("Daten vorhanden bis ..., X Tage fehlen") unter Obmann → "EDA-Daten
  importieren". Siehe `app.md` Abschnitt 12 für den noch zu bauenden App-Bildschirm.
- **03.09.2026 (PR folgt):** Push-Benachrichtigungen (APNs) -- Postfach-Element an Obmann/Admin,
  neue Rechnung an Mitglied, Einspeisung-Schwelle mit Hysterese an Mitglied. DB-Trigger
  (`migrate_20260903.sql`) + `Push.php` (ES256-JWT, HTTP/2) + `POST /api/v1/push/register`,
  `POST /api/v1/push/unregister`, `GET`+`POST /api/v1/notifications/settings`,
  `POST /api/v1/admin/settings/apns[/test]`. Zustellung über Host-Cron
  (`scripts/send_pending_push.php`, jede Minute). Braucht noch Patricks echte
  Apple-Developer-Zugangsdaten (Team-ID/Key-ID/Bundle-ID/.p8-Key) -- ohne die bleibt die
  Warteschlange liegen, alles andere ist fertig. Dabei nebenbei einen echten Bug gefunden und
  behoben: `invoices.sent_at` wurde in der gesamten bisherigen Plattform NIE gesetzt (auch das
  "letzte Rechnung"-Dashboard-Widget war dadurch schon vorher immer leer) -- `Billing::finalize()`
  setzt es jetzt beim Freigeben eines Abrechnungslaufs.
- **19.08.2026:** Dateidownloads über die App (`/api/v1/documents`,
  `/api/v1/manager/members/:id/files/:fileid/download`) hatten teils keine Dateiendung
  (`member_files.name` ist nur eine freie Anzeige-Bezeichnung) -- iOS konnte sie dadurch nicht
  öffnen. `filenameWithExtension()` (`webapp/src/functions.php`) ergänzt die echte Endung jetzt
  serverseitig, sowohl im Dateinamen der Liste als auch im `Content-Disposition`-Header.
- **19.08.2026 (PR folgt):** Rolle `admin` im App-Token (`role: "admin"`, community-unabhängig),
  Admin-Endpunkte: EEG-Übersicht/-Detail/Anlegen/Bearbeiten/Löschen, Nutzer & Rollen
  plattformweit, Aktivitätslog (Liste), Plattform-Einstellungen gesammelt (Mail/Graph,
  Mail-Vorlagen, MQTT, Plattform-Technik), Backup-Übersicht. Dabei nebenbei einen echten
  RLS-Bug in `/admin/communities/:id` (Web-Portal) gefunden und behoben (Mitgliederliste dort
  zeigte seit dem RLS-Fix vom 22.08. leer).
- **19.08.2026 (PR #97):** `GET /api/v1/current-power` (Live-Poll), `GET /api/v1/roles` +
  `POST /api/v1/switch-role` (Rollenwechsel ohne Neuanmeldung), ISO-8601-Zeitstempel-Fix.
- **31.08.2026 (PR #88):** Rolle `manager` im App-Token, Mitglied-Endpunkte (Verträge,
  Dokumente, DSGVO-Export, Support-Tickets, Profil/Passwort/2FA), Obmann-Endpunkte
  (`/api/v1/manager/members*` -- Liste/Detail/Anlegen/Bearbeiten/Datei-/Foto-Upload).
- **30.08.2026:** Grundgerüst `/api/v1/*` -- Login-Flow, Dashboard, Verbrauchsverlauf,
  Rechnungen + PDF, eigene Zählpunkte.

---

*Diese Datei wird von Claude bei jeder relevanten Plattform-Änderung aktualisiert -- kein
manuelles Nachtragen durch Patrick nötig, nur beim Review in Xcode als Checkliste verwenden.*
