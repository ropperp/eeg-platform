#!/usr/bin/env bash
#
# scripts/journal_persist_workaround.sh — Workaround fuer persistente Logs, wenn
# systemd-journald trotz Storage=persistent in journald.conf einfach nicht auf
# persistenten Speicher wechselt.
#
# Hintergrund (Vorfall 24.09.2026, Patricks Raspberry Pi): auf diesem Cloud-Init-Image
# meldet journald --- selbst mit korrekter journald.conf (Storage=persistent), korrekten
# Rechten/Platz auf /var/log/journal, ohne Container/Sandbox-Einschraenkung, mit stabiler
# Maschinen-ID und ohne jede Fehlermeldung (auch nicht im SYSTEMD_LOG_LEVEL=debug-Log) ---
# ausschliesslich den fluechtigen Runtime-Journal (/run/log/journal/...). Ursache trotz
# ausfuehrlicher Diagnose ungeklaert (vermutlich eine Eigenheit dieses Images/dieser
# systemd-Version). journalctl -b -1 liefert dadurch nach jedem Reboot "no persistent
# journal was found" -- genau dann, wenn man die Logs von VOR einem Haenger am meisten
# braucht (siehe docs/RASPBERRY_STABILITAET.md, Abschnitt 2.0).
#
# Statt die Ursache weiter zu jagen: journalctl -f in eine ganz normale Textdatei
# mitschreiben, die von journalds eigener Storage-Logik komplett unabhaengig ist und
# jeden Reboot problemlos ueberlebt (normale Datei auf /var, kein journald-Format).
#
# Einmalig ausfuehren:
#   sudo bash scripts/journal_persist_workaround.sh
#
# Danach nach einem Haenger/Reboot die Logs von VORHER ansehen mit z.B.:
#   grep -B5 -A20 "Received SIGTERM" /var/log/journal-persist.log | tail -100
# (oder einfach den Zeitstempel kurz vor dem bekannten Ausfallzeitpunkt suchen).

set -euo pipefail

if [ "$(id -u)" -ne 0 ]; then
    echo "Bitte mit sudo/als root ausfuehren." >&2
    exit 1
fi

cat > /etc/systemd/system/journal-persist.service << 'EOF'
[Unit]
Description=Journal-Logs in eine normale Datei mitschreiben (Workaround, Storage=persistent greift aus ungeklaertem Grund nicht)
After=systemd-journald.service

[Service]
ExecStart=/bin/sh -c 'exec journalctl -f -o short-iso >> /var/log/journal-persist.log'
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
EOF

cat > /etc/logrotate.d/journal-persist << 'EOF'
/var/log/journal-persist.log {
    daily
    rotate 14
    compress
    missingok
    notifempty
    copytruncate
}
EOF

systemctl daemon-reload
systemctl enable --now journal-persist.service

echo "journal-persist.service laeuft. Test:"
sleep 2
tail -5 /var/log/journal-persist.log
