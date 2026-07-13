# Claude-Code-Anweisung (konsolidiert): EEG-Plattform stromfueralle.at

**Stand:** Juli 2026 · **Von:** Patrick Ropper, EEG Strompool Feldkirchen Süd-West
**Ersetzt** die früheren Einzelanweisungen (Mitglieder/Verträge, Online-Anmeldung). Bei Widerspruch gilt dieses Dokument.

## 0. Kontext & Stack

- Self-hosted Plattform für Erneuerbare-Energie-Gemeinschaften: eigener VPS, **Docker**, **PostgreSQL** (mit TimescaleDB-Hypertables für Messwerte), **Traefik** als Reverse Proxy, Domain **stromfueralle.at**.
- E-Mail-Versand über **Microsoft Graph API** (OAuth2 Client Credentials, App `stromfueralle-mailer`, Absender `noreply@stromfueralle.at`) — Vorlage in `send_mail.py`, Setup-Doku in `Anleitung_Mailversand_Azure_GraphAPI.md`. **Kein SMTP Basic Auth.**
- Bestehendes Datenmodell laut ER-Diagramm: `communities`, `users`, `user_roles`, `members`, `metering_points`, `esp_measurements`, `eda_measurements`, `eda_imports`, `tariff_config`, `tax_config`, `billing_runs`, `invoices`, `invoice_items`. **Daran anknüpfen, nicht parallel neu bauen.**
- Vorhandene LaTeX-Dokumente im CI-Design (grün/türkis): `Beitrittserklärung.tex`, `InfoBlatt.tex` — als Stil-Referenz und Template-Basis nutzen.

**Vorgehen:** Zuerst Repo analysieren und dich an bestehende Struktur/Framework halten. Migrationen sauber anlegen. Am Ende Diff-Zusammenfassung, nicht selbstständig deployen. Bei Unklarheiten nachfragen statt raten.

---

## 1. Datenmodell-Erweiterungen (Postgres)

```sql
-- Verträge (1 aktiver Vertrag je Mitglied+Typ, DB-seitig erzwungen)
contracts(
  id PK, member_id FK, community_id FK,
  typ,                    -- 'bezug' | 'einspeisung'
  vertragsnummer UNIQUE,  -- z. B. RC108175-B-0001 / RC108175-E-0001
  vertragsbeginn DATE,    -- editierbar, darf in der Vergangenheit liegen (z. B. 2026-07-01)
  preis_snapshot JSONB,   -- bezug_ct / einspeisung_ct aus tariff_config zum Generierungszeitpunkt
  status,                 -- 'erstellt' | 'versendet' | 'unterschrieben' | 'unterschrieben_upload' | 'storniert'
  pdf_pfad, pdf_sha256,
  signed_pdf_pfad, signed_pdf_sha256,
  sign_token UNIQUE,      -- für den Signatur-Link, zufällig ≥32 Byte, mit Ablaufdatum
  sign_token_expires_at,
  signature_image,        -- PNG/Base64 vom signature_pad
  signed_at, signer_ip, signer_user_agent,
  storno_grund, storniert_am,
  erstellt_von FK users, created_at
)
-- Partieller Unique-Index: UNIQUE (member_id, typ) WHERE status != 'storniert'

-- Datei-Ablage pro Mitglied (Uploads, Scans, Beitrittserklärungen)
member_files(id PK, member_id FK, name, pfad, mime, sha256, hochgeladen_von, created_at)

-- Online-Anmeldungen (Felder = Papier-Beitrittserklärung, siehe Abschnitt 5)
membership_applications(..., status 'pending'|'approved'|'rejected', signature_image, signed_at, signer_ip)

-- Internes Postfach
notifications(id PK, community_id FK, typ, titel, text, referenz_typ, referenz_id,
              status 'offen'|'erledigt', created_at, erledigt_am, erledigt_von)

-- E-Mail-Konfiguration & Vorlagen (Admin-editierbar)
email_settings(id PK, tenant_id, client_id, client_secret, sender_email,
               secret_ablaufdatum DATE, updated_at, updated_by)
email_templates(id PK, key,   -- 'vertrag_versand' | 'rechnung_versand' | 'anmeldung_bestaetigung' | ...
               betreff, body_html, updated_at, updated_by)
```

`members` ergänzen um: `kundennummer` (KdNr, fortlaufend je Community, **im UI überall sichtbar**), `mandatsreferenz`, `beitrittsdatum`, Anrede/Titel/Geburtsdatum/Adresse/Kontakt, `iban` (maskiert anzeigen), Felder aus der Beitrittserklärung (Stromlieferant, Speicher, andere EEG …).
`metering_points` ergänzen um Felder für die Einspeiser-Vereinbarung: `engpassleistung_kw`, `erzeugungsart` (Default „Photovoltaik"), `gst_nr`, `katastralgemeinde`, `anlagenadresse`.

---

## 2. Mitgliederverwaltung (Admin/Obmann)

- Mitgliederliste: KdNr, Name, Ort, Mitgliedsart (Bezug/Einspeisung/beides), Vertragsstatus-Badges je Typ. **Filter:** „Vertrag noch nicht erstellt", „noch nicht unterschrieben", Mitgliedsart, Status.
- **KdNr prominent** auf Liste und Detailseite (wird auch analog auf Papier geschrieben).
- Mitglied anlegen/bearbeiten mit allen Feldern der Beitrittserklärung; Validierung: IBAN (mod-97), Zählpunkt (AT + 31 Stellen = 33 Zeichen), E-Mail, Pflichtfelder.
- **Datei-Upload pro Mitglied:** Datei hochladen + Namen vergeben → Ablage unter der KdNr (siehe Dateistruktur), Liste der Dateien auf der Detailseite.
- Kein Hard-Delete; DSGVO-Löschung als separate bestätigungspflichtige Admin-Aktion.

### Dateistruktur (Filesystem, Docker-Volume)

```
/opt/<ordnername>/rc108175/            # ein Ordner je EEG (Marktnummer)
  vereinsdokumente/                    # Statuten, AGB, Preisliste (PDF, siehe URL-Pfade)
  <kundennummer>/                      # ein Ordner je Mitglied
    beitrittserklaerung_<KdNr>.pdf
    vertrag_<vertragsnummer>.pdf                 # generierte Version
    vertrag_<vertragsnummer>_unterschrieben.pdf  # signierte Version
    uploads/<vergebener_name>.<ext>
```

Gut backupbar, Pfade in der DB referenzieren, niemals öffentlich ausliefern (nur authentifizierte Downloads).

---

## 3. Vertragsgenerierung über LaTeX-Templates

### Vorlagen

- **Zwei Templates**, abgelegt als versionierte `.tex`-Dateien im Repo (z. B. `templates/vertrag_bezug.tex`, `templates/vertrag_einspeisung.tex`):
  1. **Energie- und Leistungsbezugsvereinbarung** — Basis: Muster der Koordinationsstelle V2 August 2022, **Text 1:1 übernehmen**.
  2. **Vereinbarung Bestand & Nutzung Energieerzeugungsanlage (Überschusseinspeiser)** — Basis: Muster V2 August 2022, Text 1:1.
- Die beiden DOCX-Muster liegen bei. Vollständig nach LaTeX übertragen; die **gelb markierten Stellen werden Variablen**. Einleitungsseiten der Muster („Einleitende Bemerkungen … Haftungsausschluss") **nicht** in den Kundenvertrag übernehmen.
- Kopf-/Fußzeile im CI-Design von `Beitrittserklärung.tex` (Farbbalken, Logo, Vereinsfußzeile) ist erlaubt, der Vertragstext selbst bleibt unverändert.
- Fixe Vereinsdaten aus `communities`: EEG Strompool Feldkirchen Süd-West, ZVR 1778816746, RC 108175, Sitz Feldkirchen in Kärnten, vertreten durch den Obmann.

### Variablen (Jinja2 mit LaTeX-kompatiblen Delimitern, z. B. `\VAR{...}`)

Mitglied: Anrede, Titel, Vorname, Nachname, Geburtsdatum, Adresse, KdNr. Vertrag: Vertragsnummer, Vertragsbeginn, Datum. Zählpunkt(e) Bezug bzw. Erzeugung; bei Einspeisung zusätzlich: Erzeugungsart, Engpassleistung (kW), kWp, Gst-Nr./Katastralgemeinde, Anlagenadresse. Preise: `bezug_ct` bzw. `einspeisung_ct` als **Snapshot aus `tariff_config`** (Werte in DB-Feld `preis_snapshot` UND ins PDF — nicht nur Verweis auf die Preisliste).

**Wichtig — nicht ins PDF:** **keine IBAN, kein BIC, keine Bankdaten** im Vertrag (Bankwechsel darf keinen neuen Vertrag erfordern; keine Klartext-IBAN in Dokumenten). SEPA bleibt nur im System.

### Rendering-Pipeline

1. Daten sammeln → **alle Benutzereingaben LaTeX-escapen** (`& % $ # _ { } ~ ^ \` — Injection-Schutz!).
2. Template rendern → `latexmk -pdf` non-interaktiv in Temp-Verzeichnis. TeX Live gehört ins Docker-Image (eigener Builder-Container oder ins App-Image; `texlive-latex-extra` + verwendete Pakete genügen, kein Full-Install).
3. PDF unter `/opt/.../<KdNr>/vertrag_<nr>.pdf` ablegen, SHA-256 in DB.
4. Fehlschlag der Kompilierung → Fehler loggen + Notification ins interne Postfach, kein halbfertiger Datensatz.

### Regeln (wie besprochen)

- **Nur die Verträge anbieten, die das Mitglied laut Datensatz will** (Bezug und/oder Einspeisung).
- **Einmalig generieren:** partieller Unique-Index (siehe Datenmodell) + Button-Sperre + serverseitige Idempotenz (Transaktion). Erneuter Download liefert byte-identisch die gespeicherte Datei.
- Neu generieren nur über „**Stornieren & neu erstellen**" (Grund-Pflichtfeld, alter Vertrag bleibt archiviert als `storniert`).
- Generierung blockieren mit klarer Meldung, wenn Pflichtdaten fehlen (z. B. kein Erzeugungs-Zählpunkt oder keine Gst-Nr. für den Einspeiservertrag).
- Vor Generierung: Vorschau der einfließenden Daten mit Bestätigung.

---

## 4. E-Signatur (beschlossene Lösung)

### Vereinsseite

- Im Admin-Panel wird die **eingescannte Unterschrift des Obmanns** (PNG, transparent) hinterlegt.
- Sie wird **bei der Generierung automatisch** an der Vereins-Unterschriftsposition eingesetzt → der Vertrag geht vereinsseitig fertig gezeichnet raus.
- (Der Obmann kann wichtige PDFs zusätzlich manuell mit ID Austria qualifiziert signieren — dafür ist nichts zu implementieren.)

### Mitgliederseite — Signatur-Link (Standardweg)

1. Admin klickt „**Zur Unterschrift senden**" → E-Mail an das Mitglied (Template `vertrag_versand`, Graph API) mit einmaligem Link `stromfueralle.at/signatur/<sign_token>` (Token ≥ 32 Byte zufällig, Ablauf z. B. 30 Tage, danach neu versendbar). Status → `versendet`.
2. Seite zeigt den **vollständigen Vertrag als PDF im Browser** (Viewer, scrollbar, mobil tauglich) + Download-Möglichkeit.
3. Darunter Canvas-Unterschriftsfeld (**signature_pad**, Finger/Maus/Stylus) + Checkbox „Ich habe den Vertrag gelesen und stimme zu".
4. Beim Absenden speichert der Server: Unterschriftsbild, Zeitstempel (UTC), IP, User-Agent, Dokument-Hash.
5. Server erzeugt die signierte Version **durch Stempeln des gespeicherten PDFs** (z. B. pikepdf/reportlab-Overlay): Unterschriftsbild an der Mitglieds-Unterschriftszeile + **angehängte Audit-Trail-Seite** (Vertragsnummer, SHA-256 des Originals, Zeitstempel, IP, E-Mail-Adresse, an die der Link ging). **Nicht neu aus LaTeX kompilieren** — das Mitglied muss exakt das Dokument signieren, das es gesehen hat.
6. Ablage als `vertrag_<nr>_unterschrieben.pdf`, Status → `unterschrieben`, Bestätigungs-Mail mit signiertem PDF an das Mitglied, Notification ins interne Postfach.

### Fallback — Upload

Auf der Vertragskarte im Admin: „**Unterschriebene Version hochladen**" für Papier-Scans oder extern (z. B. ID Austria) signierte PDFs → Ablage wie oben, Status → `unterschrieben_upload`.

---

## 5. Digitale Beitrittserklärung `/anmeldung`

Basis bleibt die bestehende Detail-Anweisung (Online-Anmeldung Teil A/B/C) — hier nur die **verbindlichen Ergänzungen/Änderungen**:

- **EEG-Auswahl:** Suchfeld mit Live-Autocomplete gegen `communities`, Auswahl setzt RC-Nummer und Dokument-Links (`/rc<rc>/statuten`, `/rc<rc>/agb`, `/rc<rc>/preisliste`, `/datenschutz`).
- **Alle Felder** der Papier-Beitrittserklärung (siehe `Beitrittserklärung.tex`): Stammdaten, Bezug (Jahresverbrauch, Zählpunkt), Einspeisung (kWp, geplante kWh, Zählpunkt), Speicher, andere EEG, SEPA (IBAN/BIC/Kontoinhaber).
- **Rechtliche Zustimmungen:** alle 6 Checkboxen einzeln, alle Pflicht — Absenden erst möglich, wenn alle gesetzt. **AGB, Datenschutz und Statuten müssen im eingebetteten Viewer bis ganz nach unten gescrollt worden sein**, erst dann wird die jeweilige Checkbox aktiv.
- **Unterschrift:** signature_pad-Canvas direkt im Formular (Pflicht), + Zeitstempel/IP speichern.
- **Nach dem Absenden:** Antrag als `pending` speichern; **PDF der ausgefüllten Beitrittserklärung aus dem bestehenden LaTeX-Template** (`Beitrittserklärung.tex` mit Variablen + Unterschriftsbild) erzeugen; Bestätigungs-Mail an Antragsteller, Notification ins interne Postfach.
- **Freigabe-Workflow:** Admin prüft die Daten → „Freigeben" legt das Mitglied unter der richtigen EEG an (KdNr wird erst jetzt vergeben, Mandatsreferenz erzeugt, PDF in den Kundenordner verschoben). „**Ablehnen/Löschen**" verwirft den Antrag ohne KdNr-Vergabe (wichtig für Testanmeldungen).

---

## 6. Mitgliedsbeitrag-Logik

- 2 €/Monat, **verrechnet quartalsweise** (6 €/Quartal), Verrechnungszeitraum = Quartal.
- Berechnung **monatsgenau ab Beitrittsmonat** — kein tagesgenaues Anteilrechnen: Beitritt Mitte/Ende eines Monats = voller Monat.
- Beispiel: Beitritt im 2. Monat eines Quartals → erstes (Teil-)Quartal 4 €, danach 6 € je vollem Quartal.
- Beitragshöhe aus `tariff_config.beitrag_eur` (nicht hart codieren). Beitragsposten fließen in den Billing-Run/`invoice_items`.

## 7. Mandatsreferenz (SEPA)

- Format: **`S00000F<Jahr der Mandats-Erstellung>A<KdNr>`** — z. B. `S00000F2026A10005`.
- Ersetzt alle früheren Schemata (nicht mehr `Jahr+KdNr`, nicht `Jahr+RC+KdNr`).
- Vergabe bei Mitglieds-Freigabe, danach **unveränderlich**; Creditor-ID je EEG aus `communities` (Strompool: `AT14EEG00000086499`).

---

## 8. Internes Postfach (Task-/Benachrichtigungs-Center)

Eigener Menüpunkt mit Badge (Anzahl offener Einträge). Einträge (Typ, Referenz-Link, offen/erledigt):

- Vertrag noch nicht erstellt (Mitglied aktiv, aber gewünschter Vertragstyp fehlt)
- Vertrag unterschrieben zurückgekommen (digital signiert oder Upload)
- Posten bereit zur Rechnungsfreigabe (Billing-Run wartet auf Freigabe)
- Smart-Meter-Daten, die keinem Mitglied/Zählpunkt zugeordnet werden können
- Neue Online-Anmeldung eingegangen (`pending`)
- **E-Mail-Sendefehler** (sofort, mit Fehlertext und Empfänger)
- Warnung: Client Secret läuft bald ab (ab 30 Tage vor `secret_ablaufdatum`)

---

## 9. E-Mail-Versand & Admin-Einstellungen

- Versand über **Microsoft Graph API** wie in `send_mail.py` (msal, Client Credentials, `POST /users/<sender>/sendMail`), als wiederverwendbaren Service im Backend implementieren, Anhänge (PDF) unterstützen.
- **Secrets:** Tenant-ID, Client-ID, Client-Secret **niemals ins Repo** (auch nicht in mitcommitteter `.env` — `.env` in `.gitignore`). Initial aus Umgebungsvariablen; zur Laufzeit gelten die Werte aus der DB (`email_settings`), damit ein Secret-Wechsel ohne Deployment funktioniert.
- **Admin-Panel „E-Mail-Einstellungen":**
  - Felder: Tenant-ID, Client-ID, Client-Secret, Absender-Adresse, Secret-Ablaufdatum. Anzeige im Klartext ist ok (nur Rolle Admin, Seite nicht cachen, Werte nicht in Logs).
  - **„Test-Mail senden"**-Button zur sofortigen Prüfung nach Secret-Tausch.
  - **Editierbare Vorlagen** für: Vertragsversand (Signatur-Link), Rechnungsversand, Anmeldebestätigung — je Betreff + HTML-Text.
  - Platzhalter in Vorlagen: `{{anrede}}, {{titel}}, {{vorname}}, {{nachname}}, {{kdnr}}, {{vertragsnummer}}, {{eeg_name}}, {{signatur_link}}, {{rechnungsnummer}}, …` — daneben ein **„i"-Hilfe-Icon**, das alle verfügbaren Variablen mit Beschreibung auflistet. Unbekannte Platzhalter beim Speichern abfangen.
- **Jeder Sendefehler** erzeugt sofort eine Notification im internen Postfach (inkl. Graph-Fehlercode, siehe Fehlertabelle in der Anleitung).

---

## 10. Infrastruktur

- **Traefik**: Ports 80/443, TLS via **Let's Encrypt (HTTP-01)**, automatischer Redirect 80 → 443, Proxy auf die Webapp. Zertifikats-Storage persistent (Volume für `acme.json`).
- TeX Live im Docker-Setup (siehe Abschnitt 3); LaTeX-Kompilierung mit Timeout und ohne Shell-Escape (`-no-shell-escape`).
- `/opt/<ordnername>/…` als Volume mounten; regelmäßiges Backup ist dadurch dateibasiert möglich.

## 11. Sicherheit & DSGVO

- Alle Admin-Funktionen nur mit Login + Rollenprüfung (`user_roles`); Signatur-Seite ist die einzige öffentliche vertragsbezogene Route (Token-geschützt, Rate-Limit).
- PDFs/Uploads nie über statische, erratbare URLs — nur authentifizierte bzw. token-geprüfte Downloads.
- IBAN im UI maskiert (`AT61 **** **** **** 3600`), vollständig nur im SEPA-Export; **nicht in Vertrags-PDFs**.
- Audit-Log: Anlage/Änderung Mitglieder, Generierung/Storno/Versand/Signatur von Verträgen, Änderungen an E-Mail-Einstellungen.
- Zustimmungen (Anmeldung + Signatur) mit Zeitstempel/IP speichern; Lösch-/Aufbewahrungsfristen für abgelehnte Anträge definieren (z. B. 3 Monate).

## 12. Tests & Akzeptanzkriterien

- [ ] Duplikat-Schutz: zweiter Generierungsversuch desselben Typs schlägt fehl — auch bei parallelen Requests (DB-Constraint-Test).
- [ ] Erneuter Download = byte-identische Datei (Hash-Vergleich); signierte Version verändert das Original nicht.
- [ ] LaTeX-Escaping: Mitglied mit Namen wie `Müller & Söhne 100%_test` erzeugt fehlerfrei ein PDF ohne Injection.
- [ ] Kein IBAN-String in irgendeinem generierten Vertrags-PDF (automatisierter Text-Extrakt-Test).
- [ ] Signatur-Flow Ende-zu-Ende: Mail mit Link → Anzeige → Unterschrift → gestempeltes PDF mit Audit-Seite → Status + Notification. Abgelaufener/falscher Token wird abgewiesen.
- [ ] Beitragslogik: Beitritt 15.08. → Q3-Rechnung 4 € (Aug+Sep), Q4 6 €. Beitritt 01.07. → Q3 6 €.
- [ ] Mandatsreferenz-Format `S00000F2026A<KdNr>` korrekt und unveränderlich.
- [ ] `/anmeldung`: Absenden nur mit allen Zustimmungen + Scroll-Pflicht erfüllt + Unterschrift; Ablehnen vergibt keine KdNr.
- [ ] Mail-Sendefehler landet als Notification im Postfach.
- [ ] 80→443-Redirect und gültiges LE-Zertifikat.

## 13. Offene Platzhalter (Patrick liefert / prüfen lassen)

- Endgültige LaTeX-Vertragstexte nach Übertragung der Muster → Stellen mit gelben Passagen als `[AUSFÜLLEN]` markieren und Liste zurückgeben; **juristische Prüfung der ausgefüllten Fassung** (Koordinationsstelle/Jurist).
- Scan der Obmann-Unterschrift (PNG, transparent).
- Gst-Nr./Katastralgemeinde der Erzeugungsanlagen bestehender Mitglieder.
- Kündigungsfristen/Indexierung (VPI) in den Verträgen — Werte aus dem Muster übernehmen oder anpassen?
- Bestätigung der Hausbank, dass die gezeichnete Signatur fürs SEPA-Mandat akzeptiert wird.
