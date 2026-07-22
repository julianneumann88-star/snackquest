# Operations Runbook

## Täglich

Health-Endpunkt, Login-Erreichbarkeit, DB-Status und Cron-Ausführung von `php8.3-cli bin/maintenance.php` prüfen. Nur Fehler-/Warnlogs ansehen; keine Secrets oder Nutzerinhalte in Tickets kopieren.

## Wöchentlich

Backup-Frische, Speicherplatz, Mailzustellung, OFF degraded rate und fehlgeschlagene Auth-Versuche prüfen. Dependencies auf Sicherheitsupdates prüfen, aber nicht ungeprüft produktiv aktualisieren.

## Bekannte Fehlerbilder

- OFF Timeout/429: Cache bzw. stale Cache nutzen; private Custom Products bleiben verfügbar.
- Google `redirect_uri_mismatch`: exakten produktiven Callback und Origin im dedizierten Client vergleichen.
- SMTP-Fehler: IONOS-Zugang/Port/TLS prüfen; keine Verifizierungslinks aus Logs veröffentlichen.
- Kamera verweigert: HTTPS, Browserpermission und manuelle Eingabe prüfen.
- AI Timeout: Kernprofil bleibt deterministisch; Bridge-Key/Health nur serverseitig prüfen.
- DB 5xx: IONOS-Verbindung, Credentials und Migrationstatus prüfen; keine Stacktraces ausgeben.
- Service Worker alt: Version/Cache bump, `sw.js` no-cache und Scope prüfen.

## Incident

Zeit/Request-ID sammeln, Auswirkung begrenzen, betroffene Secrets rotieren, Daten-/Meldepflicht prüfen, Patch in isolierter Umgebung verifizieren, kontrolliert deployen und Nachanalyse festhalten.
