# Obsidian-Vault-Sync für eeg-platform

Dieser Ordner ist die Brücke zwischen dem `eeg-platform`-Repo und Patricks lokalem
Obsidian-Vault (`/Users/ropper_p/Ropper Obsidian`). Notizen hier werden mit
`CLAUDE.md` im Repo-Root abgeglichen — wenn sich die Infrastruktur-Doku ändert,
wird die entsprechende Notiz hier mit aktualisiert (und umgekehrt).

## Einmaliges Setup (lokal, im Vault)

Damit nur dieser Unterordner (nicht der ganze Code-Repo) im Vault landet, per
Git-Sparse-Checkout einbinden:

```bash
cd "/Users/ropper_p/Ropper Obsidian"
mkdir eeg-platform-notes && cd eeg-platform-notes
git init
git remote add origin https://github.com/ropperp/eeg-platform.git
git sparse-checkout init --cone
git sparse-checkout set obsidian
git pull origin main
```

Danach taucht `eeg-platform-notes/obsidian/` als normaler Unterordner im Vault auf
und Obsidian indiziert die `.md`-Dateien darin wie jede andere Notiz.

## Laufender Abgleich

Neue Notizen von hier abholen:
```bash
cd "/Users/ropper_p/Ropper Obsidian/eeg-platform-notes"
git pull origin main
```

Lokale Änderungen zurückspielen:
```bash
cd "/Users/ropper_p/Ropper Obsidian/eeg-platform-notes"
git add -A
git commit -m "Notizen aktualisiert"
git push origin main
```

## Inhalt

- [`Infrastruktur.md`](./Infrastruktur.md) — Spiegel von `CLAUDE.md` (Netzwerk-Architektur,
  Docker-Stack, bekannte Probleme & Lösungen) in Obsidian-Notizform.
