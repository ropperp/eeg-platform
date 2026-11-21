# ADR 0008: Statuten-Generator über persönlichen Einladungslink

**Status:** angenommen, umgesetzt am 21.11.2026 (v0.11.0)

## Kontext
Gründer einer Erneuerbare-Energie-Gemeinschaft sollen ihre Vereinsstatuten aus einem Fragebogen erzeugen
können. Der Generator soll **nicht öffentlich** sein: Vorher wird in einem Beratungsgespräch geklärt, ob ein
Verein/EEG überhaupt passt (oder BEG, gemeinsame Energienutzung, Eigenversorgung). Interessenten haben noch
keine Gemeinschaft und kein Konto auf der Plattform. Fachliche Beschreibung: `docs/STATUTEN_GENERATOR.md`.

## Entscheidung
- **Einladungslink statt Login:** Der Platform-Admin erzeugt pro Interessent einen Link `/statuten/{token}`.
  Ein Konto für Interessenten (Keycloak/Portal) wäre für einen einmaligen Fragebogen zu schwer.
- **Token:** 32 Zufallsbytes (`random_bytes`, 256 Bit), base64url. In `statute_invites.token_hash` steht nur
  der SHA-256. Lookup über den Hash (UNIQUE-Index), danach zusätzlich `hash_equals`. Falsch geformte Token
  gehen nicht an die Datenbank. Folge: Der Link ist nur **einmal** sichtbar (direkt nach dem Anlegen, über die
  Sitzung, nicht in der URL). Geht er verloren, erzeugt der Admin einen neuen, der alte wird ungültig.
- **Zustände:** `open` → `generated` (gespeichert), `revoked` (gespeichert), „abgelaufen“ wird aus `expires_at`
  berechnet. Nach dem Erzeugen ist der Link 90 Tage lang **nur Download**; der Fragebogen ist über den Link
  gesperrt. Änderungen nur durch den Admin (neue Fassung `version + 1` oder neue Einladung).
- **Plattformweit ohne RLS:** Die Tabellen gehören keiner Gemeinschaft (wie `platform_settings`). Schutz:
  Admin-Routen prüfen `isPlatformAdmin()`, die öffentlichen Routen den Token. SQL nur im Repository.
- **Missbrauchsschutz:** `RateLimiter::isStatuteBlocked()`: 10 falsche Links je IP in 15 Minuten und
  120 Aufrufe je IP in 10 Minuten (schützt auch den PDF-Dienst). Antworten mit `X-Robots-Tag: noindex`
  und `Cache-Control: no-store`. Die globale `Referrer-Policy: strict-origin-when-cross-origin` verhindert,
  dass der Pfad mit dem Token an fremde Seiten geht. CSRF wie überall (Sitzung auch ohne Login).
- **PDFs eingefroren:** Jede Fassung legt drei PDFs unter `storage/statuten/<id>/v<n>/` ab. „Freigeben“
  erzeugt sie neu ohne Kopfzeile „Entwurf – keine Rechtsberatung“.
- **Querverweise nur per `\label`/`\ref`** in `statuten.tex`, auch in den wahlabhängigen Bausteinen aus PHP.
- **Löschkonzept:** `scripts/statute_cleanup.php` (Cron) löscht Einladungen samt Entwürfen und PDFs 12 Monate
  nach der letzten Erzeugung bzw. nach Ablauf/Widerruf (`docs/DSGVO.md`).
- **Später:** `statute_drafts.community_id` ist vorbereitet, um aus einem Entwurf eine Gemeinschaft (Mandant)
  anzulegen. Noch keine Oberfläche.

## Folgen
- Wer den Link kennt, sieht und ändert den Entwurf, bis er erzeugt ist. Das ist gewollt (Weitergabe innerhalb
  der Gründergruppe), deshalb nur fachliche Daten und freiwillige Namen, keine Geburtsdaten oder Anschriften.
- Restrisiko: Ein weitergeleiteter Link gibt die Statuten frei. Gegenmaßnahme: Ablauf, Widerruf, neuer Link.
