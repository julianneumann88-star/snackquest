# Store-Artefakte

In diesem Verzeichnis liegen ausschließlich öffentliche Store-Metadaten. Signaturdateien, Passwörter, APK/AAB und Xcode-Quellpakete werden bewusst außerhalb des Repositorys im privaten Release-Artefaktordner aufbewahrt.

## Android

- Paket-ID: `org.julianneumann.snackquest`
- Signaturalias: `snackquest-release`
- Digital-Asset-Link: `android/assetlinks.json`
- Öffentlicher Zielpfad: `https://julian-neumann.org/.well-known/assetlinks.json`

Der Digital-Asset-Link muss bei jedem Wechsel des Android-Signaturschlüssels aktualisiert werden. Der aktuelle Schlüssel ist der für Version `1.0.0` erzeugte Release-Schlüssel.

## iOS

Das PWABuilder-Xcode-Quellpaket verwendet die Bundle-ID `org.julianneumann.snackquest`. Apple-Team, Provisioning Profile, Xcode-Signatur, physischer Gerätetest und App-Store-Review sind externe Freigabeschritte und werden nicht als abgeschlossen behauptet.
