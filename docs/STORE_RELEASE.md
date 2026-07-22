# Store Release

SnackQuest ist zuerst eine installierbare PWA. Store-Veröffentlichung ist nur erfolgreich, wenn Entwicklerkonto, Gebühren, Signatur, Datenschutzangaben, Paketprüfung und Store-Review real abgeschlossen sind.

Vorbereitung:

- PWABuilder gegen die öffentliche, vollständig getestete Manifest-/Service-Worker-URL laufen lassen.
- Microsoft-/Android-Pakete generieren und Signatur-/Domainnachweis prüfen.
- Für iOS PWABuilder- oder Capacitor-8-Projekt nur mit Apple-Team, Bundle-ID, Icons, Splash, Privacy Manifest und Xcode-Signatur erzeugen.
- Storetexte, echte Screenshots, Support-/Privacy-URLs, Kamera- und Datenerklärungen bereitstellen.
- Kamera, Offline-Draft, OAuth-Redirect und Account Delete im jeweiligen Paket testen.

Der aktuelle Repository-Stand behauptet keine Einreichung oder Freigabe. Blockierende externe Schritte werden in [LIVE_RELEASE.md](LIVE_RELEASE.md) exakt dokumentiert.
