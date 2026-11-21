# Statuten-Generator

Erzeugt aus einem Fragebogen **Statuten für einen Verein „Erneuerbare-Energie-Gemeinschaft“** (EEG) als PDF,
dazu eine Checkliste zur Vereinsanzeige und eine Protokollvorlage für die Gründungsversammlung.
Der Generator ist **nicht öffentlich**: Ein Plattform-Admin erzeugt nach einem Beratungsgespräch einen
persönlichen Einladungslink. Entscheidung und Sicherheitsmodell: `docs/adr/0008-statuten-generator.md`.

> Haftungshinweis (steht in jedem Dokument): Vorlage, keine Rechtsberatung. Vor Einreichung von einer
> fachkundigen Person prüfen lassen. Kopfzeile „Entwurf – keine Rechtsberatung“, bis der Admin eine Fassung freigibt.

Später möglich (nicht gebaut): BEG-Verein, Genossenschafts-Satzung (bei GmbH nur Hinweis: Gesellschaftsvertrag
braucht Notar), aus einem Entwurf eine Gemeinschaft (Mandant) anlegen (`statute_drafts.community_id` ist vorbereitet).

## Ablauf

1. Interessent fragt über `/kontakt` an („Energiegemeinschaft gründen“), Beratungsgespräch.
2. Admin → **Statuten-Einladungen** (`/admin/statuten-einladungen`): Bezeichnung, Gültigkeit (Standard 30 Tage),
   optional Vorbelegung (Nahebereich, Netzbetreiber, Bundesland). Der Link wird **einmal** angezeigt.
3. Interessent füllt den Fragebogen unter `/statuten/{token}` aus, Zwischenspeichern ist erlaubt.
4. „Statuten erzeugen“ prüft die Regeln, erzeugt die drei PDFs (Fassung 1) und schaltet den Link auf **nur Download**
   (90 Tage). Der Fragebogen ist über den Link danach gesperrt.
5. Änderungen nur über den Admin: Entwurf bearbeiten → „Neue Fassung erzeugen“ (Fassung n+1) oder neue Einladung.
   Nach fachlicher Prüfung „Freigeben“: PDFs ohne Entwurfs-Kopfzeile.

| Zustand | Bedeutung | Link zeigt |
|---|---|---|
| offen | angelegt, noch nicht erzeugt | Fragebogen |
| erzeugt | Statuten erzeugt | nur Download (90 Tage ab letzter Erzeugung) |
| abgelaufen | `expires_at` vorbei (berechnet, nicht gespeichert) | Hinweis (410) |
| widerrufen | vom Admin gesperrt | Hinweis (410) |

## Fragebogen (Felder → `StatuteQuestionnaire`)

**A. Grunddaten:** Modell EEG und Rechtsform Verein fest. Vereinsname, Sitz (Gemeinde), Bundesland, Bezirk/Gebiet,
Nahebereich (lokal/regional), Lokal-/Regional-ID, Netzebene, Netzbetreiber, Anzahl Gründungsmitglieder (≥ 2),
Gründer mit Funktion (freiwillig, nur für Protokoll und Checkliste).

**B. Zweck & Geld:** Energieträger, Anlagenmodell (Überschuss-/Volleinspeiser, eigene Anlagen), „nicht gemeinnützig“
fest, Eintrittsgeld, Mitgliedsbeitrag als Bandbreite, Verrechnung, wer die Energiepreise festlegt (Vorstand/MV),
Nachschusspflicht, Abrechnungsfrist, **erwarteter Gesamtumsatz pro Jahr und Umsatzsteuer** (Kleinunternehmer/Regel).

**C. Mitgliedschaft:** ordentlich (fest), außerordentlich, Ehrenmitglieder; Aufnahme durch; Austrittsfrist;
Ausschluss (Monate Verzug, Mahnungen, durch wen); Rechtsnachfolge; Pflicht zur Datenfreigabe. Fest: Mitgliederkreis
nach § 6 Abs 1 Z 45 ElWG (keine Großunternehmen), schutzbedürftige Haushalte nicht ausschließbar.

**D. Organe:** Vorstandsgröße 2–6, Schriftführer ja/nein, Stellvertretung je Funktion, Funktionsperiode 1–5 Jahre,
Wiederwahl, Kooptierung, Vertretung nach außen, Vier-Augen-Grenze, Dirimierungsrecht, Umlaufbeschluss,
Rechnungsprüfer (2–3, Periode). Schlichtungseinrichtung fest (§ 8 VerG).

**E. Mitgliederversammlung:** Abstand 1–5 Jahre, ao. MV binnen 2–4 Wochen, Einladungsfrist, E-Mail, virtuell,
Beschlussfähigkeit, Vollmacht, Mehrheit für Statutenänderung/Auflösung.

**F. Auflösung & Datenschutz:** Vermögensverwendung; Datenschutz-Paragraph fest (DSGVO/DSG, Netzbetreiber/EDA,
Auftragsverarbeitung, § 132 BAO).

## Prüfregeln (`StatuteRules`)

Fehler (verhindern das Erzeugen):
1. Pflichtfelder: Name, Sitz, Bundesland, Netzbetreiber.
2. Gründer ≥ 2; Vorstand ≥ 2 (§ 5 Abs 3 VerG) und groß genug für alle gewählten Funktionen; Rechnungsprüfer ≥ 2
   (§ 5 Abs 5 VerG) und laut Gründerliste nicht im Vorstand.
3. MV-Abstand ≤ 5 Jahre (§ 5 Abs 2 VerG) **und** ≤ Funktionsperiode des Vorstands.
4. Nahebereich nur lokal/regional (§ 66 Abs 1 i. V. m. § 70 Abs 6 Z 3/4 ElWG).
5. Mindestbeitrag ≤ Höchstbeitrag.
6. Kleinunternehmerregelung gewählt, aber erwarteter Umsatz > 55.000 € (§ 6 Abs 1 Z 27 UStG).

Hinweise (immer angezeigt, ändern nichts): Name muss den Zweck erkennen lassen (§ 4 VerG); Umsatz nahe der
Kleinunternehmergrenze (10 % Toleranz nur bis Jahresende); Regelbesteuerung = Formular U12, Bindung 5 Jahre;
Jahresabschluss ab 1 Mio €, Abschlussprüfer ab 3 Mio € (§ 22 VerG); Körperschaftsteuer; zwingende
Verbraucher-Kündigungsrechte bei der Austrittsfrist; Vertretung ohne Obmann-Stellvertretung (Kassier).

Fest eingebaut, kein Feld: „nicht gemeinnützig“, schutzbedürftige Haushalte, Mitgliederkreis, Schlichtung, Datenschutz.

## Ausgabe

| Dokument | Vorlage | Inhalt |
|---|---|---|
| Statuten | `latex-service/templates/statuten.tex` | §§ 1–18; Querverweise nur per `\label`/`\ref` |
| Checkliste Vereinsanzeige | `latex-service/templates/statuten_anzeige.tex` | § 11 VerG, Finanzamt, USt, Netzbetreiber |
| Protokollvorlage 1. MV | vorhandene `protokoll.tex` | Tagesordnung mit Leerstellen |

Rechtsgrundlagen-Zeile: „Erneuerbare-Energie-Gemeinschaft gemäß § 79 EAG und §§ 66 ff ElWG“.
Regeln für die Vorlage: nie händische Paragraphen-Nummern (Fehler der Statuten V1.0: falsche Verweise in §§ 10 und 14);
ein `\ref` auf einen Absatz ergibt „§ n Abs m“. `tests/statute_invite_test.php` prüft, dass jedes `\ref` ein `\label` hat.

PDFs liegen eingefroren unter `/opt/eeg/webapp-storage/statuten/<einladung>/v<n>/`.

## Rechtsstand (RIS, geprüft 21.11.2026)

| Thema | Regel | Quelle |
|---|---|---|
| Mindestinhalt Statuten | 11 Punkte (Name, Sitz, Zweck, Mittel, Mitgliedschaft, Rechte/Pflichten, Organe, Bestellung/Periode, Beschlüsse, Schlichtung, Auflösung) | § 3 Abs 2 VerG |
| Mitgliederversammlung | mindestens alle 5 Jahre; 1/10 der Mitglieder kann Einberufung verlangen | § 5 Abs 2 VerG |
| Leitungsorgan | mindestens 2 natürliche Personen | § 5 Abs 3 VerG |
| Rechnungsprüfer | mindestens 2, unabhängig und unbefangen | § 5 Abs 5 VerG |
| Insichgeschäfte | Zustimmung eines anderen vertretungsbefugten Organwalters | § 6 Abs 4 VerG |
| Schlichtung | Rechtsweg nach 6 Monaten offen | § 8 VerG |
| Rechnungslegung | Einnahmen-Ausgaben-Rechnung binnen 5 Monaten; ab 1 Mio € Jahresabschluss, ab 3 Mio € Abschlussprüfer | §§ 21, 22 VerG |
| Auflösung | Mitteilung an die Behörde binnen 4 Wochen | § 28 VerG |
| EEG | § 66 ElWG verweist auf § 79 Abs 1, 2, 4 EAG; Mitglieder: natürliche Personen, Gemeinden, Behörden, öffentlich-rechtliche Stellen, KMU | § 66, § 6 Abs 1 Z 45 ElWG (BGBl. I 91/2025) |
| Nahebereich | Lokalbereich (Niederspannung), Regionalbereich (Mittelspannung) | § 70 Abs 6 Z 3/4 ElWG |
| Lieferantenpflichten | ab 100 kW (Gemeinschaft) bzw. 30 kW (Haushalt), entfällt mit Organisator | § 69 ElWG |
| Kleinunternehmer | 55.000 € im Vor- und laufenden Jahr; bis 10 % Überschreitung noch bis Jahresende befreit | § 6 Abs 1 Z 27 UStG (ab 01.01.2025) |
| Verzicht (Regelbesteuerung) | schriftlich (U12), ab Jahresbeginn, Bindung mindestens 5 Kalenderjahre | § 6 Abs 3 UStG |
| Körperschaftsteuer | EEG als Verein KöSt-pflichtig, keine Mindest-KöSt | BMF, PV-Erlass 30.07.2025 |

**Vor Freigabe einer Fassung im RIS prüfen**: Der Wortlaut von § 79 EAG und §§ 21, 22, 28 VerG wurde nur über
Zusammenfassungen geprüft. Die Schwelle für schutzbedürftige Haushalte (10 %) gilt nur für Gemeinden mit eigener
Anlage in der gemeinsamen Energienutzung (§ 68 Abs 6 ElWG). Die Statuten schließen solche Haushalte deshalb
allgemein nicht aus und nennen keine Quote.
