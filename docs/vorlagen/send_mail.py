"""
Mailversand über Microsoft Graph API (OAuth2 Client Credentials Flow)
Projekt: stromfueralle.at

Setup-Details siehe: Anleitung_Mailversand_Azure_GraphAPI.md

Installation:
    pip install msal requests --break-system-packages
"""

import msal
import requests

# ============================================================
# KONFIGURATION — hier anpassen
# ============================================================

# Wer bekommt die Mail
TO_ADDRESS = "empfaenger@example.com"

# Betreff
SUBJECT = "Betreff hier eintragen"

# Inhalt (HTML erlaubt, z.B. <p>, <b>, <a href="...">)
BODY_HTML = """
<p>Hier den Mailtext eintragen.</p>
"""

# ============================================================
# ANMELDEDATEN — Azure App "stromfueralle-mailer"
# Hinweis: Bei Erneuerung des Client Secrets hier den neuen Wert eintragen.
# Besser: in Produktion über Umgebungsvariablen / .env laden statt hier
# hart zu codieren.
# ============================================================

TENANT_ID = "<TENANT_ID>"          # NIEMALS echte Werte hier/im Repo — siehe Anweisung Abschnitt 9
CLIENT_ID = "<CLIENT_ID>"          # echte Werte nur in .env bzw. der email_settings-Tabelle
CLIENT_SECRET = "<CLIENT_SECRET>"
SENDER_EMAIL = "noreply@stromfueralle.at"

# ============================================================
# AB HIER NICHTS ÄNDERN
# ============================================================


def get_access_token() -> str:
    """Holt ein OAuth2-Token über den Client Credentials Flow."""
    authority = f"https://login.microsoftonline.com/{TENANT_ID}"
    app = msal.ConfidentialClientApplication(
        CLIENT_ID, authority=authority, client_credential=CLIENT_SECRET
    )
    result = app.acquire_token_for_client(
        scopes=["https://graph.microsoft.com/.default"]
    )
    if "access_token" not in result:
        raise Exception(f"Token-Fehler: {result.get('error_description')}")
    return result["access_token"]


def send_mail(to_address: str, subject: str, body_html: str) -> None:
    """Sendet eine Mail über die Microsoft Graph API."""
    token = get_access_token()
    url = f"https://graph.microsoft.com/v1.0/users/{SENDER_EMAIL}/sendMail"
    headers = {
        "Authorization": f"Bearer {token}",
        "Content-Type": "application/json",
    }
    payload = {
        "message": {
            "subject": subject,
            "body": {"contentType": "HTML", "content": body_html},
            "toRecipients": [{"emailAddress": {"address": to_address}}],
        },
        "saveToSentItems": "true",
    }
    response = requests.post(url, headers=headers, json=payload)
    if response.status_code == 202:
        print("Mail erfolgreich gesendet.")
    else:
        print(f"Fehler ({response.status_code}): {response.text}")


if __name__ == "__main__":
    send_mail(TO_ADDRESS, SUBJECT, BODY_HTML)
