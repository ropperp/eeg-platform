# Datenschutz (DSGVO) — Konzept der Plattform

Kurzüberblick, wie die Plattform die zentralen Betroffenenrechte der DSGVO technisch umsetzt.
Kein Rechtsrat — das ersetzt keine datenschutzrechtliche Prüfung durch die EEG, sondern
dokumentiert die vorhandenen Funktionen (relevant u. a. für die Diplomarbeit: Sicherheits-/
Datenschutzkonzept).

## Grundprinzip: Datentrennung
Jede Energiegemeinschaft (Mandant) sieht ausschließlich ihre eigenen Daten. Technisch
erzwungen über **PostgreSQL Row-Level Security** (`community_id`-Policies, siehe
`database/init.sql`) plus explizite `WHERE community_id = ...`-Filter im Anwendungscode. Ein
Mitglied sieht im Portal nur seine eigenen Daten.

## Art. 15 / Art. 20 — Auskunft & Datenübertragbarkeit
Alle zu einer Person gespeicherten personenbezogenen Daten können als maschinenlesbare
**JSON-Datei** exportiert werden:

- **Mitglied selbst:** Portal → *Meine Daten* → „Datenauskunft herunterladen"
  (`GET /portal/my/dsgvo-export`).
- **Manager/Obmann (auf Auskunftsersuchen):** Mitgliederverwaltung → Mitglied öffnen →
  „DSGVO-Export" (`GET /portal/members/:id/dsgvo-export`).

Der Export enthält Stammdaten, Login-Konto (ohne Passwort-Hash), Zählpunkte, Beitritts-
erklärung, Verträge, Rechnungen inkl. Positionen, hochgeladene Dateien (Metadaten) und
API-Zugänge. **Bewusst nicht enthalten** (Sicherheit): Passwort-Hash, API-Key-Hash,
Signier-Token sowie die eingebetteten Unterschriftsbilder. Jeder Export wird im
`audit_log` protokolliert.

## Art. 17 — Löschung vs. gesetzliche Aufbewahrung
Ein Spannungsfeld: Das „Recht auf Löschung" kollidiert bei einer Abrechnungsplattform mit
**steuer-/unternehmensrechtlichen Aufbewahrungspflichten** (in Österreich i. d. R. 7 Jahre für
Rechnungen/Buchhaltungsbelege, § 132 BAO). Die Plattform bildet das über zwei Stufen ab
(siehe Mitglied-Detail):

- **Login löschen** — entfernt nur den Online-Zugang; der Mitglieds-/Abrechnungsdatensatz
  bleibt bestehen.
- **Wirklich löschen (deaktivieren)** — sperrt den Zugang und markiert das Mitglied als
  inaktiv; abrechnungsrelevante Daten (Verträge, Rechnungen, Dateien) bleiben aus
  Aufbewahrungsgründen erhalten und sind nur noch im Archivbereich sichtbar.
- **EEG endgültig löschen** (Platform-Admin) — entfernt eine komplette Gemeinschaft samt aller
  zugehörigen Daten kaskadierend (`ON DELETE CASCADE`).

Eine echte, vollständige physische Löschung einzelner Mitglieder nach Ablauf der
Aufbewahrungsfrist ist als organisatorischer Prozess vorzusehen (noch nicht automatisiert).

## Weitere Maßnahmen
- **Transport:** ausschließlich HTTPS (SSL-Terminierung am nginx-Proxy).
- **Passwörter:** bcrypt-Hash (`password_hash`), nie im Klartext, nicht im Export.
- **Zugriffskontrolle:** rollenbasiert (Mitglied / Manager / Platform-Admin); Rechnungs-PDFs
  sind gegen IDOR abgesichert (nur eigenes Mitglied bzw. Manager der EEG).
- **Protokollierung:** sicherheits-/datenschutzrelevante Aktionen landen im `audit_log`.
- **Hosting:** in Österreich/DACH (Raspberry-Pi-Server der EEG), keine Weitergabe der
  Energiedaten an Dritte.

## Offene Punkte / Empfehlungen
- 2-Faktor-Authentifizierung für Manager/Admin (geplant).
- Automatisierter Löschlauf nach Ablauf der Aufbewahrungsfrist.
- Verzeichnis von Verarbeitungstätigkeiten (Art. 30) und Datenschutzerklärung als
  organisatorische Dokumente außerhalb des Codes.
