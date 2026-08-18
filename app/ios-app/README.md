# Strom für alle — iOS-App

Native iOS-Begleit-App zur EEG-Plattform (Xcode/SwiftUI), gegen die bestehende
`/api/v1/*`-Schnittstelle des Hauptprojekts (siehe `../../docs/APP_API.md`).

## Status

Projekt wird lokal in Xcode angelegt und hier eingecheckt. Noch kein Xcode-Projekt vorhanden --
dieser Ordner ist der vorgesehene Ablageort dafür.

## Aufbau (geplant)

```
app/ios-app/
  StromFuerAlle.xcodeproj/     -- von Xcode erzeugt
  StromFuerAlle/
    App/                       -- App-Einstiegspunkt
    Networking/                -- API-Client, Auth-Token-Handling (Keychain)
    Models/                    -- Codable-Structs passend zu APP_API.md
    Views/                     -- SwiftUI-Screens
    Resources/                 -- Assets, Farben, Icons
```

Feature-/Design-Referenz für den Aufbau: siehe die begleitende Spezifikation (an Patrick als
Artifact übergeben).
