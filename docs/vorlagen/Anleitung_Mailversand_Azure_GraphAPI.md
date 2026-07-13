# Anleitung: E-Mail-Versand über Microsoft Graph API (OAuth2)

**Projekt:** stromfueralle.at
**Erstellt:** Juni 2026
**Zweck:** Diese Anleitung beschreibt, wie die App-Registrierung in Azure/Entra ID für den automatisierten Mailversand eingerichtet wurde — relevant für spätere Verlängerung des Client Secrets oder Fehlersuche.

---

## Hintergrund

Microsoft deaktiviert schrittweise die alte SMTP-Basic-Authentifizierung (Benutzername + Passwort) für Office 365 / Exchange Online. Statt SMTP mit Passwort verwenden wir daher die **Microsoft Graph API** mit **OAuth2 Client Credentials Flow** (App-zu-App-Authentifizierung ohne Benutzerinteraktion). Das ist der von Microsoft empfohlene, zukunftssichere Weg für serverseitigen/automatisierten Mailversand.

---

## Übersicht der eingerichteten Komponenten

| Komponente | Wert |
|---|---|
| App-Name (Azure) | `stromfueralle-mailer` |
| Tenant (Verzeichnis-ID) | *(nicht im Repo — siehe Anweisung Abschnitt 9, Wert liegt separat bei Patrick)* |
| Application (Client) ID | *(nicht im Repo — siehe Anweisung Abschnitt 9, Wert liegt separat bei Patrick)* |
| API-Berechtigung | Microsoft Graph → `Mail.Send` (Application Permission) |
| Absender-Postfach | `noreply@stromfueralle.at` (Shared Mailbox) |
| Client Secret | im Azure Portal unter "Zertifikate & Geheimnisse" — **nicht** hier dokumentiert, siehe unten |

> ⚠️ Das **Client Secret** selbst ist hier bewusst **nicht** eingetragen, da dieses Dokument auch geteilt wird. Es muss separat sicher abgelegt werden (Passwort-Manager, `.env`-Datei, o.ä.) und wird bei jeder Erneuerung neu generiert.

---

## Schritt-für-Schritt: App-Registrierung erstellen

### 1. App registrieren

1. [portal.azure.com](https://portal.azure.com) öffnen → **Microsoft Entra ID** → **App registrations** → **New registration**
2. Name vergeben (z.B. `stromfueralle-mailer`)
3. **Supported account types:** "Accounts in this organizational directory only" (Single tenant) — wir brauchen keine Mehrmandantenfähigkeit
4. Redirect URI: leer lassen (nicht nötig für Client Credentials Flow)
5. **Register** klicken

→ Auf der Übersichtsseite findet man danach **Application (Client) ID** und **Directory (Tenant) ID**.

### 2. Client Secret erstellen

1. In der App → **Zertifikate & Geheimnisse** (Certificates & secrets)
2. Tab **"Geheime Clientschlüssel"** → **"Neuer geheimer Clientschlüssel"**
3. Beschreibung eingeben (z.B. `mailer-secret-2026`)
4. Ablaufzeit wählen (empfohlen: 12–24 Monate)
5. **Hinzufügen**

⚠️ Der **Wert (Value)** wird nur **einmal** direkt nach dem Erstellen angezeigt — sofort kopieren und sicher abspeichern. Die "Geheime ID" daneben wird **nicht** benötigt.

### 3. API-Berechtigung vergeben

1. In der App → **API-Berechtigungen** → **"+ Berechtigung hinzufügen"**
2. **Microsoft Graph** auswählen
3. **Anwendungsberechtigungen** (Application permissions) wählen — **nicht** "Delegierte Berechtigungen"
4. Nach `Mail.Send` suchen → Checkbox aktivieren → **Berechtigungen hinzufügen**
5. Auf der Berechtigungs-Übersichtsseite: **"Administratorzustimmung für [Tenant] erteilen"** klicken und bestätigen

→ Status bei `Mail.Send` muss danach grün ✅ "Gewährt für ..." anzeigen.

### 4. Absender-Postfach (Shared Mailbox) anlegen

`Mail.Send` als Application Permission erlaubt der App, **als jedes Postfach im Tenant** zu senden — deshalb sollte ein dediziertes Postfach verwendet werden, nicht ein persönliches.

1. [admin.cloud.microsoft](https://admin.cloud.microsoft) → Microsoft 365 Admin Center
2. Im linken Menü: **Gruppen** → **Freigegebene Postfächer** (Shared mailboxes)

   > Hinweis: Dieser Menüpunkt ist manchmal versteckt. Falls nicht sichtbar, im Suchfeld oben "freigegebenes Postfach" eingeben oder direkt diese URL aufrufen: `https://admin.cloud.microsoft/?#/groups/:/SharedMailboxes`

3. **"Freigegebenes Postfach hinzufügen"**
4. Name (z.B. `No-Reply stromfueralle.at`) und E-Mail-Adresse (z.B. `noreply`) eingeben
5. **Hinzufügen**

Eine Shared Mailbox benötigt **keine eigene Lizenz**. Es kann nach dem Anlegen 5–15 Minuten dauern, bis sie systemweit verfügbar ist.

---

## Erneuerung des Client Secrets (wichtig!)

Das Client Secret läuft nach der gewählten Zeit ab (aktuell gültig bis **27.06.2028**). Vor Ablauf:

1. Azure Portal → App `stromfueralle-mailer` → **Zertifikate & Geheimnisse**
2. **Neuer geheimer Clientschlüssel** erstellen (Schritt 2 oben wiederholen)
3. Neuen Wert in der `.env` / Konfiguration des Python-Skripts (`CLIENT_SECRET`) eintragen
4. Altes Secret nach erfolgreichem Test des neuen Secrets löschen (Mülltonnen-Symbol in der Liste)

---

## Mögliche Fehlerquellen

| Fehler | Ursache |
|---|---|
| `AADSTS7000215` | Client Secret falsch kopiert/abgelaufen |
| `403 Forbidden` | Admin-Consent für `Mail.Send` fehlt oder noch nicht propagiert (kurz warten) |
| `MailboxNotEnabledForRESTAPI` o.ä. | Absender-Postfach existiert nicht oder ist noch nicht aktiv |
| `550 5.7.30 Basic authentication is not supported` | Würde nur bei altem SMTP-Verfahren auftreten — betrifft uns nicht, da wir Graph API/OAuth2 nutzen |

---

## Code

Siehe `send_mail.py` — Vorlage zum Versenden von Mails über dieses Setup. Empfänger, Betreff und Inhalt sind ganz oben im Skript konfigurierbar.
