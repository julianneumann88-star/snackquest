# Deployment auf IONOS

Ziel ist ausschließlich der private, außerhalb des Repositorys konfigurierte Webspace-Pfad für SnackQuest, öffentlich als `https://julian-neumann.org/snackquest`. Andere Sites, DNS-Einträge, Datenbanktabellen und die AI-Bridge dürfen nicht verändert werden.

## Gate

1. `npm ci && npm run build`
2. Lint, Secret Audit, Dependency Audit, Unit- und E2E-Tests.
3. Google-Client lokal in ignoriertem `config/google-oauth.json` bereitstellen.
4. Privates Deployment-Skript zunächst im Dry-Run prüfen.
5. Erst nach Kontrolle der exakten Zielpfade mit dem expliziten Execute-Schalter ausführen.

Das infrastrukturspezifische Deployment-Skript ist absichtlich nicht Teil des öffentlichen Repositorys. Es liest lokale Secrets ohne Ausgabe, verbindet per SSH/SFTP, prüft den exakten Webroot, legt vor Überschreiben ein zeitgestempeltes Remote- und Datenbank-Backup an, lädt nur eine Allowlist hoch, erzeugt `config.local.php` ohne BOM, migriert mit PHP 8.3 CLI und führt öffentliche Smoke-Checks aus. Es entfernt oder verschiebt keine anderen Remote-Pfade.

Nach Deployment: Google Login, SMTP, OFF, Upload, Account Export/Delete, Share/Widerruf, PWA und Smartphone-Kamera real testen. Die Maintenance-CLI täglich per IONOS-Cron ausführen.
