# Testbericht

Stand vor Produktionskonfiguration, 22. Juli 2026:

- Build: erfolgreich.
- PHP-/JavaScript-Lint: erfolgreich.
- Secret Audit: erfolgreich.
- `npm audit --audit-level=high`: 0 bekannte Schwachstellen.
- Unit/Integration: 12 bestanden, 0 fehlgeschlagen.
- Playwright: 30 bestanden, 0 fehlgeschlagen (Desktop + Mobile Chromium, serieller Lauf zur Vermeidung lokaler Browser-Ressourcenkonflikte).
- Accessibility: keine serious/critical axe-Verstöße auf geprüften Seiten.
- Responsive: kein horizontales Überlaufen in den geprüften 390×844-Flows.

Die E2E-Suite verwendet isolierte Testkonten, Log-Mail und private Testprodukte; sie erzeugt keine Fake-Daten in Produktion. Google, echtes SMTP, reale Smartphone-Kamera, IONOS-MariaDB, Live-Bridge und öffentlicher URL-Smoke werden im Release-Schritt separat protokolliert. Erst danach wird [LIVE_RELEASE.md](LIVE_RELEASE.md) auf „live“ gesetzt.
