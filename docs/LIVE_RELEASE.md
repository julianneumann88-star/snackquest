# Live Release

Version: `1.0.1`

Produktions-URL: [julian-neumann.org/snackquest](https://julian-neumann.org/snackquest)

Repository: [julianneumann88-star/snackquest](https://github.com/julianneumann88-star/snackquest)

Status: **öffentlich veröffentlicht und produktiv geprüft**

## Abgeschlossene Release-Gates

- [x] Produktcode und 23 isolierte MariaDB-Tabellen mit Präfix `sq_`
- [x] Build, PHP-/JavaScript-Lint, Secret- und Dependency-Audit
- [x] 13 Unit-/Integrationstests und 30 Desktop-/Mobile-Browser-E2E-Tests
- [x] PWA-Assets, Offline-Entwürfe, SEO, Datenschutz und Betriebsdokumentation
- [x] Neues eigenständiges Markenlogo samt responsivem Wordmark, Favicons, PWA-, Maskable-, Monochrome- und Social-Preview-Assets
- [x] Dediziertes Google-Cloud-Projekt und OAuth-Webclient mit PKCE
- [x] Reale Google-Anmeldung, Profilanlage und Onboarding im Produktivsystem
- [x] IONOS-Produktion mit PHP 8.3, MariaDB, SMTP und App-/Datenbank-Backups
- [x] Öffentlicher Kamera-Start mit ZXing-Fallback und manueller Barcode-Fallback
- [x] Reale Open-Food-Facts-Abfrage für einen gültigen EAN
- [x] SMTP-Zustellung mit bestandener SPF-, DKIM- und DMARC-Prüfung
- [x] Portfolio-Eintrag und Root-Sitemap auf `julian-neumann.org`
- [x] Search-Console-Inhaberschaft, Sitemap-Einreichung und Indexierungsantrag
- [x] Search-Console-Sitemap erfolgreich verarbeitet: 8 Seiten erkannt
- [x] PWABuilder-Store-Readiness, signiertes Android-APK/AAB und iOS-Xcode-Quellpaket
- [x] Öffentliches GitHub-Showcase, Tag und Release `v1.0.1`
- [x] Täglicher Retention-Job über GitHub Actions mit dediziertem SSH-Schlüssel

## Produktionsnachweise vom 22. Juli 2026

- Letztes vollständiges Deployment-Backup: `snackquest-deploy-20260722-214411`; zusätzlich Dump aller 23 `sq_`-Tabellen.
- 22 öffentliche Smoke-Ziele einschließlich Health, Manifest, Service Worker, Robots, Sitemap und Kernassets antworteten erfolgreich.
- Die neue Markenfamilie ist unter der Produktions-URL aktiv: Header-Wordmark, Favicons, installierbare App-Icons, Maskable-/Monochrome-Varianten und Open-Graph-Bild werden aus einem versionierten Master reproduzierbar erzeugt.
- Google OAuth leitete nach erfolgreicher Zustimmung zurück, legte das echte Profil an und öffnete die Scan-Ansicht.
- Der manuelle Produktions-Wartungslauf endete mit `Maintenance complete.`; der identische GitHub-Actions-Probelauf war erfolgreich.
- GitHub CI war für alle bisherigen Pushes grün.
- PWABuilder erkannte Manifest, Service Worker und Offline-Unterstützung und meldete SnackQuest als paketbereit.
- Unabhängiges Headless-Chromium bestätigte eine aktive Service-Worker-Registrierung und eine erfolgreiche Offline-Navigation zur SnackQuest-Offlineseite.
- Das signierte Android-Paket verwendet `org.julianneumann.snackquest`; das iOS-Quellpaket dieselbe Bundle-ID.
- Der Android-Domainnachweis ist unter `/.well-known/assetlinks.json` veröffentlicht und liefert die Paket-ID sowie den Fingerprint des Release-Zertifikats mit HTTP 200 aus.

## Offene externe Distributionsschritte

- Reale Installation und Kameraprüfung auf einem physischen iPhone und Android-Gerät sind nicht behauptet; Mobile-Chromium und der Live-Kamerastart im Desktop-Browser sind geprüft.
- Search Console verarbeitet die Sitemap erfolgreich und erkennt 8 Seiten. Die neue URL war bei der Einzelprüfung noch nicht im Google-Index; die Indexierungsanfrage ist gestellt und die weitere Verarbeitung liegt bei Google.
- Die erste echte Passwort-Reset-Mail wurde zugestellt und bestand SPF, DKIM und DMARC, landete bei Gmail aber im Spamordner. Eine spätere Reputationskontrolle bleibt sinnvoll.
- Die signierten Android- und iOS-Vorbereitungspakete von `v1.0.0` bleiben sicher archiviert. Die Neugenerierung für `v1.0.1` wurde mit unveränderter Paket-/Bundle-ID und unverändertem Android-Schlüssel angestoßen, aber PWABuilder lieferte am 22. Juli 2026 weder für Android noch iOS eine Datei aus. Am selben Tag wurden im offiziellen Projekt mehrere gleichartige Packaging-Fehler gemeldet. Eine Store-Freigabe für `v1.0.1` wird daher nicht behauptet.
- Einreichungen und Freigaben bleiben zusätzlich blockiert, bis die jeweiligen Entwicklerkonten, Gebühren, Plattformsignaturen, Geräteprüfungen und Store-Reviews real vorhanden sind. Für Windows fehlen insbesondere die echten Partner-Center-Publisherdaten.
