# Store Release

SnackQuest ist zuerst eine installierbare PWA. PWABuilder hat die öffentliche Produktions-URL am 22. Juli 2026 erneut geprüft: Manifest, Service Worker, Service-Worker-Logik und Offline-Unterstützung wurden erkannt; der Bericht meldet die PWA als bereit für Store-Pakete.

## Erzeugte Pakete

### Android

- Signiertes APK und AAB für Paket-ID `org.julianneumann.snackquest`
- Version `1.0.0.0`, Version Code `1`
- Signaturalias `snackquest-release`; Signaturinhaber Julian Neumann, Land `DE`
- Paket-ZIP SHA-256: `E3F7416F0867948E62ED12161C62DD83FE476783D646C472A0B6761E7DE170FA`
- Zertifikat-Fingerprint: `8B:4F:F2:A5:D8:25:F7:BA:63:C5:30:1F:C8:AD:55:11:B3:33:81:FD:10:8D:3B:49:4F:CB:A9:68:5C:93:66:2B`
- Digital Asset Link im Repository: [`../store/android/assetlinks.json`](../store/android/assetlinks.json)
- Öffentlicher Digital Asset Link: <https://julian-neumann.org/.well-known/assetlinks.json> (HTTP 200, `application/json`)

Signaturdatei und Passwörter liegen ausschließlich im privaten Release-Artefaktordner und werden nicht committet. Die zuvor mit PWABuilder-Standardwerten erzeugte Testausgabe ist separat als veraltet markiert und darf nicht veröffentlicht werden.

### iOS

- PWABuilder-Xcode-Quellpaket mit Bundle-ID `org.julianneumann.snackquest`
- Paket-ZIP SHA-256: `886D28056E247DAAF47DC0771D63DBF5EB4B1BE9F7B592082F8F57B903FC90B4`
- Icons, Start-URL und Produktionsdomain sind enthalten.

Das Quellpaket ist noch keine signierte IPA und keine App-Store-Einreichung. Dafür sind ein echtes Apple Developer Team, Provisioning, Xcode-Signatur, Privacy-Angaben, ein physischer iOS-Test und Apples Review erforderlich.

### Windows

PWABuilder verlangt echte Package-/Publisher-IDs aus dem Microsoft Partner Center. Ohne diese Identität wird bewusst kein Paket mit erfundenen Standardwerten erzeugt.

## Noch externe Freigabeschritte

- Google Play Console: Entwicklerkonto, Gebühren, Storeeintrag, Datenschutzformular, echter Android-Gerätetest, Upload und Review
- Apple App Store: Apple Developer Account, Team/Provisioning/Signatur, Xcode-Archiv, echter iPhone-Test, Storeeintrag und Review
- Microsoft Store: Partner-Center-Identität, Package-/Publisher-IDs, Storeeintrag, Paketprüfung und Review
- Für alle Stores: echte Screenshots, Altersfreigaben, Support-/Privacy-URLs und Kamera-/Datenangaben

Eine Store-Veröffentlichung gilt erst als erfolgreich, wenn Konto, Gebühren, Signatur, Einreichung und Review real abgeschlossen sind. Der aktuelle Stand behauptet daher Paketreife, aber keine Store-Freigabe.
