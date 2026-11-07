# Vorlagen & Referenzunterlagen

Quellmaterial für die geplante Erweiterung (siehe `Anweisung_Plattform_Gesamt.md`).
Noch nicht in die Plattform integriert — dient als Basis für kommende Phasen.

- `InfoBlatt.tex` — LaTeX-Quelle des Infoblatts im CI-Design (Grün/Türkis-Verlauf, `tcolorbox`).
  Die beiden Grafiken `icon-white.png` und `EEG Grafik.png` liegen seit 08.11.2026 daneben (aus
  dem bisherigen Infoblatt-PDF in voller Auflösung extrahiert). Kompilieren direkt in diesem
  Ordner: `pdflatex InfoBlatt.tex` (zweimal). Das Ergebnis als `infoblatt.pdf` über
  `/admin/templates` hochladen und zusätzlich nach
  `webapp/public/assets/docs/infoblatt-eeg-strompool-feldkirchen-suedwest.pdf` kopieren (das ist
  die im Image mitgelieferte Standard-Fassung).
- ~~`Beitrittserklaerung.tex`~~ — **seit 07.11.2026 entfernt.** Das Papier-Beitrittsformular
  erzeugt jetzt die Plattform selbst (`latex-service/templates/beitrittserklaerung_leer.tex`,
  abrufbar unter `/rc<RC>/beitrittserklaerung.pdf`), mit denselben Zustimmungstexten wie das
  Online-Formular (einzige Quelle: `webapp/src/consent_texts.php`). Die alte Fassung steht in der
  Git-Historie; ihr Zustimmungswortlaut ist als Version `v1-offline` in `consent_texts.php`
  erhalten.
- `EnergieundLeistungsbezugsvereinbarungV2August2022.docx`,
  `VereinbarungUeberschusseinspeiserV2August2022.docx` — Vertragsmuster der Koordinationsstelle,
  Text 1:1 in die neuen LaTeX-Templates zu übernehmen (gelb markierte Stellen → Variablen).
  Einleitungsseiten der Muster nicht in den Kundenvertrag übernehmen.
- `erdiagramm.pdf` — ER-Diagramm des *aktuellen* (bereits implementierten) Datenmodells.
- `Anweisung_Plattform_Gesamt.md` — konsolidiertes Lastenheft für die Gesamterweiterung
  (Verträge, E-Signatur, Online-Beitritt, SEPA, Postfach, E-Mail-Versand, Infrastruktur).

- `send_mail.py`, `Anleitung_Mailversand_Azure_GraphAPI.md` — Referenz für den Graph-API-Mailversand.
  **Tenant-ID und Client-ID wurden vor dem Commit aus beiden Dateien entfernt** (Platzhalter
  `<TENANT_ID>`/`<CLIENT_ID>` bzw. Hinweistext) — diese Werte gehören gemäß Abschnitt 9 der
  Anweisung niemals ins Repo, auch nicht in Referenzmaterial. Echte Werte liegen separat bei
  Patrick und werden erst zur Laufzeit über `.env`/die `email_settings`-Tabelle eingespielt.

Noch offen (siehe Abschnitt 13 der Anweisung): Scan der Obmann-Unterschrift.
