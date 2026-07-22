# DNS und Domains

Die Nutzeranweisung legt den Unterpfad `https://julian-neumann.org/snackquest` fest. Daher ist kein neuer DNS-Eintrag nötig und bestehende IONOS-Records bleiben unverändert.

Alle pfadabhängigen Werte müssen konsistent sein:

- App URL: `https://julian-neumann.org/snackquest`
- Base Path: `/snackquest`
- Google Origin: `https://julian-neumann.org`
- Google Redirect: `https://julian-neumann.org/snackquest/auth/callback`
- Service Worker Scope: `/snackquest/`
- Sitemap: `https://julian-neumann.org/snackquest/sitemap.xml`

Der Root-`robots.txt` der Hauptdomain darf nur additiv um die Sitemap ergänzt werden; bestehende Regeln bleiben erhalten.
