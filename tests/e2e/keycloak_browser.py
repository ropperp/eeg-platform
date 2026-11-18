"""Browser-Ablauf Portal <-> Keycloak für tests/e2e/keycloak_run.sh (ohne Fremdbibliotheken).

Aufruf: python3 keycloak_browser.py <portal-url> <aktion> [email passwort]
Aktionen:
  login     Anmeldung über /portal/login -> Keycloak -> /portal/auth/callback -> Dashboard; gibt "ok" aus
            und speichert die Cookies in $COOKIES (für weitere Aufrufe)
  dashboard Profil mit gespeicherten Cookies abrufen: "drin" oder "draussen"
  logout    /portal/logout folgen; "ok", wenn am Ende die Startseite kommt
  loginpage HTML der Keycloak-Anmeldeseite ausgeben (für Theme-Prüfungen)
"""
import http.cookiejar
import os
import re
import sys
import urllib.parse
import urllib.request

PORTAL = sys.argv[1].rstrip("/")
ACTION = sys.argv[2]
JAR = os.environ.get("COOKIES", "/tmp/kc-e2e-cookies.txt")

cj = http.cookiejar.MozillaCookieJar(JAR)
if os.path.exists(JAR) and os.path.getsize(JAR) > 0:
    cj.load(ignore_discard=True, ignore_expires=True)


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k):
        return None


opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(cj), NoRedirect)


def fetch(url, data=None):
    """Eine Anfrage ohne automatisches Folgen. Liefert (status, location, body)."""
    for c in cj:  # wie ein Browser auf http://localhost: Secure-Cookies gelten trotzdem
        c.secure = False
    try:
        r = opener.open(urllib.request.Request(url, data=data))
        return r.status, None, r.read().decode("utf-8", "replace")
    except urllib.error.HTTPError as e:
        return e.code, e.headers.get("Location"), e.read().decode("utf-8", "replace")


def follow(url, data=None, limit=12):
    """Weiterleitungen folgen; liefert (letzte url, status, body)."""
    for _ in range(limit):
        status, loc, body = fetch(url, data)
        data = None
        if loc:
            url = urllib.parse.urljoin(url, loc)
            continue
        return url, status, body
    raise SystemExit("zu viele Weiterleitungen")


def save():
    for c in cj:
        c.secure = False
    cj.save(ignore_discard=True, ignore_expires=True)


if ACTION == "login":
    email, password = sys.argv[3], sys.argv[4]
    url, status, body = follow(PORTAL + os.environ.get("LOGIN_PATH", "/portal/login"))
    m = re.search(r'<form id="kc-form-login"[^>]*action="([^"]+)"', body)
    if not m:
        print("keine Keycloak-Anmeldeseite (" + url + ")")
        save()
        sys.exit(0)
    action = m.group(1).replace("&amp;", "&")
    url, status, body = follow(action, urllib.parse.urlencode({"username": email, "password": password}).encode())
    save()
    # Ziel nach der Anmeldung: Dashboard (Platform-Admins werden weiter auf /admin geleitet).
    if status == 200 and (url.startswith(PORTAL + "/portal/dashboard") or url.startswith(PORTAL + "/admin")):
        print("ok")
    else:
        err = re.search(r'kc-feedback-text[^>]*>\s*([^<]+)', body) or re.search(r"<h1[^>]*>([^<]+)", body)
        print("fehlgeschlagen: %s %s %s" % (status, url, err.group(1).strip() if err else ""))
elif ACTION == "dashboard":
    status, loc, _ = fetch(PORTAL + "/portal/profile")
    print("drin" if status == 200 else "draussen")
elif ACTION == "logout":
    url, status, _ = follow(PORTAL + "/portal/logout")
    save()
    print("ok" if status == 200 and "/realms/" not in url else "fehlgeschlagen: %s %s" % (status, url))
elif ACTION == "loginpage":
    url, status, body = follow(PORTAL + "/portal/login")
    save()
    print(body)
