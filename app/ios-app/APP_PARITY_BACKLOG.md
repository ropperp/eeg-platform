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

### Platform-Admin (bisher komplett ohne App-Zugang)
- [ ] E-Mail-/Microsoft-Graph-Einstellungen (`/admin/mail-settings/*`) -- Absenderadresse,
      Zugangsdaten, Test-Mail versenden
- [ ] Mail-Vorlagen (`/admin/mail-templates`) -- Einladung, Erinnerung, etc. bearbeiten
- [ ] LaTeX-Vorlagen (`/admin/templates/*`) -- Vertrags-/Rechnungs-Vorlagen hoch-/runterladen
- [ ] EEG-Verwaltung (`/admin/communities[/:id][/delete]`) -- Energiegemeinschaften anlegen,
      konfigurieren, löschen (plattformweit, nicht nur die eigene)
- [ ] Nutzer & Rollen plattformweit (`/admin/users/:id[/{roles,delete}]`)
- [ ] Aktivitätslog (`/admin/log[/export]`) -- Audit-Trail einsehen/exportieren
- [ ] Backup-Status (`/admin/backups`)
- [ ] MQTT-Einstellungen (`/admin/mqtt-settings`) + Geräte-Fernkonfiguration
      (`/admin/mqtt-device-reconfig`)
- [ ] Plattform-Technik-Einstellungen (`/admin/settings/{esp,test-mode}`) -- ESP-Offline-
      Schwelle, Testmodus-Umschalter

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
