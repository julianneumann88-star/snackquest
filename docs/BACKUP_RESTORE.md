# Backup und Restore

Vor jedem Deployment kopiert das Release-Skript den bestehenden Remote-App-Ordner nach `/_backups/snackquest-deploy-YYYYMMDD-HHMMSS/`. DB-Backups müssen zusätzlich über IONOS oder `mysqldump` für ausschließlich `sq_*`-Tabellen erstellt werden; Uploads liegen im App-Backup.

Restore-Probe:

1. Backup-Zeitpunkt und Dateizahl prüfen.
2. DB-Dump in eine isolierte Testdatenbank importieren.
3. temporäre Konfiguration gegen die Testdatenbank starten.
4. Login, private Medien und zentrale Abfragen prüfen.

Produktiver Restore nur nach Wartungsfenster: aktuellen defekten Stand zusätzlich sichern, exakten `/snackquest`-Inhalt aus dem gewählten Backup wiederherstellen, passenden DB-Dump der `sq_*`-Tabellen importieren, Migrationstatus prüfen und Smoke-/Auth-Tests ausführen.
