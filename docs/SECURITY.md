# Sicherheitsarchitektur

- CSP, HSTS in HTTPS, `X-Content-Type-Options`, `X-Frame-Options`, Referrer- und Permissions-Policy.
- CSRF für jede Zustandsänderung; OAuth `state` plus PKCE; interne Redirect-Allow-Regel.
- Sichere Session-Cookies und Session-Regeneration.
- Prepared Statements, Tabellen-Allowlisting und serverseitige Eigentümerprüfung.
- Auth-/API-Rate-Limits und neutrale Fehlermeldungen mit Request-ID.
- Private, validierte und neu kodierte Uploads außerhalb des Webroots.
- Keine Secrets im Client/Git; Secret- und Dependency-Audit in CI.
- Private Seiten `noindex`, keine Caches, kein Service-Worker-Storage.

Known failures werden ohne sensible Daten in Logs erfasst. Bei Auth-, IDOR-, Upload- oder Secret-Verdacht gilt: betroffenen Zugriff deaktivieren, Logs/Backups sichern, Scope ermitteln, Secret rotieren, Patch testen, Nutzerpflichten bewerten und Incident dokumentieren.
