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

### Push-Benachrichtigungen (Patrick, 19.08.2026 -- eigene, größere Runde, noch nicht begonnen)

Ziel: die App soll sich wie eine "normale" App anfühlen und selbstständig benachrichtigen, ohne
dass jemand aktiv nachschauen muss. Drei Auslöser, alle unabhängig voneinander einstellbar:

1. **Obmann/Admin -- neues Postfach-Element:** sobald eine neue Systembenachrichtigung
   entsteht (unbekannter Zähler, SSID-Wechsel, neues Support-Ticket, ...), Push an alle
   Obmänner/Admins der betroffenen EEG.
2. **Mitglied -- neue Rechnung verfügbar:** sobald eine Rechnung versendet wird (`sent_at`
   gesetzt), Push an das betroffene Mitglied.
3. **Mitglied -- Einspeisung-Schwelle überschritten ("jetzt verbrauchen"):** JEDES Mitglied
   stellt in den Einstellungen eine EIGENE Schwelle ein (z. B. "benachrichtige mich, wenn ich
   mehr als X W einspeise"), ab der eine Push-Benachrichtigung kommt. **Mit Hysterese/Cooldown**
   -- nicht bei jeder 5s-Messung erneut auslösen, solange der Wert über der Schwelle bleibt,
   sondern erst wieder, nachdem der Wert zwischenzeitlich UNTER die Schwelle gefallen und DANACH
   erneut überschritten wurde (klassische Zwei-Schwellen-/Hysterese-Logik, z. B. Schwelle minus
   ein kleiner Puffer als Rückfall-Grenze) -- verhindert Spam bei einem Wert, der genau um die
   Schwelle herum schwankt. Ggf. zusätzlich eine reine zeitbasierte Mindestpause zwischen zwei
   Benachrichtigungen (z. B. nicht öfter als alle 15-30 Minuten), unabhängig von der
   Hysterese, als zweite Sicherheitsschicht.

**Braucht (grob, noch nicht im Detail geplant):**
- APNs-Anbindung (Apple Push Notification service) -- neues Zertifikat/Key in Apple Developer
  Account, Server-seitiger Push-Versand (z. B. über einen PHP-APNs-Client oder einen kleinen
  separaten Worker-Prozess).
- Neue Tabelle für Geräte-Push-Token (Device Token pro `app_sessions`-Zeile oder eigene Tabelle),
  Endpunkt zum Registrieren/Abmelden eines Geräts für Push.
- Neue Tabelle/Spalten für die Mitglied-Einstellung (Schwelle in W, an/aus) + Hysterese-Zustand
  je Mitglied (aktuell "über"/"unter" Schwelle, Zeitpunkt der letzten Benachrichtigung).
- Trigger-Logik: Postfach-Insert → Push an Obmänner/Admins; Rechnung versendet → Push ans
  Mitglied; laufender Vergleich der Live-Messwerte (mqtt-subscriber oder ein periodischer Check)
  gegen die je Mitglied konfigurierte Schwelle inkl. Hysterese.
- Neue `/api/v1/*`-Endpunkte: Push-Token registrieren/abmelden, eigene Schwelle lesen/setzen.

Absichtlich noch nicht begonnen, um es nicht oberflächlich neben dem Admin-Bereich
mitzuerledigen -- eigene, sorgfältig getestete Runde (u. a. weil ein falsch ausgelöster
Massen-Push an alle Mitglieder schwerer rückgängig zu machen ist als ein API-Bug).

---

## Bereits umgesetzt (zur Referenz, chronologisch)

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
