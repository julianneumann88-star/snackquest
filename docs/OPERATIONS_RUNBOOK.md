# Operations Runbook

## Täglich

Der Workflow `Daily maintenance` (`.github/workflows/maintenance.yml`) startet um 01:17 UTC und kann manuell ausgelöst werden. Er verwendet einen dedizierten ED25519-Schlüssel, strikte Host-Key-Prüfung und führt ausschließlich `php8.3-cli bin/maintenance.php` im SnackQuest-Verzeichnis aus. Der bestätigte Schlüsselfingerprint lautet `SHA256:CnoXOOedyZCVY62cf0XJn2USfdnCRzXWESQwvP/tvRw`.

Nach einem fehlgeschlagenen Lauf zuerst den Actions-Schritt lesen. Der CLI-Prozess liefert bei unbehandelten Fehlern Exitcode 1 und eine neutrale Request-ID; technische Details bleiben im serverseitigen App-Log. IONOS Shared Hosting erlaubt für dieses Konto keinen direkten `crontab`-Import, daher ist GitHub Actions der aktive Scheduler.

Health-Endpunkt, Login-Erreichbarkeit und DB-Status prüfen. Nur Fehler-/Warnlogs ansehen; keine Secrets oder Nutzerinhalte in Tickets kopieren.

## Wöchentlich

Backup-Frische, Speicherplatz, SMTP-Zustellung/Spamklassifizierung, OFF-Degraded-Rate, Search-Console-Sitemapstatus und fehlgeschlagene Auth-Versuche prüfen. Dependencies auf Sicherheitsupdates prüfen, aber nicht ungeprüft produktiv aktualisieren.

## Bekannte Fehlerbilder

- OFF Timeout/429: Cache bzw. stale Cache nutzen; private Custom Products bleiben verfügbar.
- Google `redirect_uri_mismatch`: exakten produktiven Callback und Origin im dedizierten Client vergleichen.
- SMTP-Fehler: IONOS-Zugang/Port/TLS prüfen; keine Verifizierungslinks aus Logs veröffentlichen.
- Kamera verweigert: HTTPS, Browserpermission und manuelle Eingabe prüfen.
- AI Timeout: Kernprofil bleibt deterministisch; Bridge-Key/Health nur serverseitig prüfen.
- DB 5xx: IONOS-Verbindung, Credentials und Migrationstatus prüfen; keine Stacktraces ausgeben.
- Service Worker alt: Version/Cache bump, `sw.js` no-cache und Scope prüfen.
- Maintenance `Permission denied`: nicht `crontab` reparieren; den bestätigten GitHub-Actions-Scheduler verwenden.

## Incident

Zeit/Request-ID sammeln, Auswirkung begrenzen, betroffene Secrets rotieren, Daten-/Meldepflicht prüfen, Patch in isolierter Umgebung verifizieren, kontrolliert deployen und Nachanalyse festhalten. Der Wartungsschlüssel ist über die markierte Zeile `snackquest-github-maintenance` in `~/.ssh/authorized_keys` einzeln widerrufbar; danach auch die fünf `IONOS_*`-Repository-Secrets entfernen oder rotieren.
