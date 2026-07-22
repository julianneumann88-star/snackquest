# Accessibility

Ziel ist WCAG 2.2 AA. Die Anwendung bietet semantische Überschriften, Labels/Fieldsets, Skip Link, sichtbare Fokusindikatoren, ausreichende Touch-Ziele, Tastaturbedienung, Statusmeldungen mit Rollen, verständliche Fehlertexte und `prefers-reduced-motion`.

Automatisierte Playwright-/axe-Prüfungen lehnen serious/critical Verstöße ab und testen Desktop sowie 390×844 Mobile ohne horizontales Überlaufen. Kamera und visuelle Produktdaten besitzen manuelle Alternativen; Farbe ist nie alleiniger Statusindikator. Vor Release werden zusätzlich Tastaturfolge, Screenreader-Namen, Zoom 200 %, Kontrast und reduzierte Bewegung manuell geprüft.
