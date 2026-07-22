# Testbericht

Letzter vollständiger Lauf: 22. Juli 2026, nach dem PWA-/HEAD-Fix.

## Automatisiert

- Build: erfolgreich.
- PHP-/JavaScript-Lint: erfolgreich.
- Secret Audit: erfolgreich.
- `npm audit --audit-level=high`: 0 bekannte Schwachstellen.
- Unit/Integration: 13 bestanden, 0 fehlgeschlagen; einschließlich `HEAD`-Routing auf die zugehörigen `GET`-Routen.
- Playwright: 30 bestanden, 0 fehlgeschlagen; Desktop- und Mobile-Chromium, serieller Lauf.
- Accessibility: keine serious/critical axe-Verstöße auf den geprüften Seiten.
- Responsive: kein horizontales Überlaufen in den geprüften 390×844-Flows.
- GitHub Actions CI: alle bisherigen Push-Läufe erfolgreich.
- PWABuilder: Manifest paketbereit; Service Worker, Service-Worker-Logik und Offline-Unterstützung erkannt.

Die E2E-Suite deckt Registrierung, Verifizierung, Login, Onboarding, private Produkte und Uploads, Bewertungen, Bibliothek, Sammlungen, Duelle, Export, Teilen/Widerruf und vollständige Kontolöschung ab. Sie verwendet eine isolierte SQLite-Datenbank, Testkonten und den Log-Mail-Transport; sie erzeugt keine Fake-Daten in Produktion.

## Produktion

- Deployment mit vorherigem App- und MariaDB-Backup: erfolgreich.
- MariaDB-Migration und Isolation von 23 `sq_`-Tabellen: erfolgreich.
- 22 öffentliche URL-/Asset-Smokes: erfolgreich.
- `HEAD /snackquest/`: HTTP 200; Service-Worker-Registrierung erfolgt ohne verzögertes `load`-Ereignis.
- Google OAuth Authorization Code + PKCE, Callback, Session, Profilanlage und Onboarding: erfolgreich.
- Kamera-Berechtigung und ZXing-Scannerstart über HTTPS: erfolgreich.
- Manueller EAN und reale Open-Food-Facts-v3.6-Produktauflösung: erfolgreich.
- Echte SMTP-Passwort-Reset-Mail: zugestellt; SPF, DKIM und DMARC bestanden, Gmail-Spamklassifizierung separat vermerkt.
- Wartungsprogramm lokal, direkt auf IONOS und aus GitHub Actions: erfolgreich.
- Unabhängiges Headless-Chromium: Secure Context, Service-Worker-Support, aktive Registrierung, kontrollierte Seite und Offline-Navigation mit HTTP 200 bestätigt.
- PWABuilder: Store-Readiness-Dialog erfolgreich; signiertes Android-APK/AAB und iOS-Xcode-Quellpaket erzeugt und anhand Paket-/Bundle-ID geprüft.
- Search Console: Inhaberschaft bestätigt, Indexierung beantragt und Sitemap erfolgreich verarbeitet; 8 Seiten erkannt.

Nicht als bestanden behauptet werden physische iPhone-/Android-Installationen, Uploads in Entwicklerportale, Store-Einreichungen oder Store-Reviews. Das iOS-Paket ist Quellcode und noch keine signierte IPA.
