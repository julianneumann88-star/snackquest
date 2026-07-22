# Rollback

Rollback-Auslöser: wiederholte 5xx, fehlgeschlagene Migration, Auth-Ausfall, Datenisolationsfehler oder defekte Kernstrecke.

1. Weitere Deployments stoppen und Zeitpunkt notieren.
2. Betroffenen Stand unverändert sichern.
3. letzten bekannten Remote-Backupordner unter `/_backups/snackquest-deploy-*` wählen.
4. Nur den exakten `/snackquest`-Pfad wiederherstellen.
5. Bei Schema-/Datenfehler den korrespondierenden `sq_*`-Dump wiederherstellen; keine CouchPilot-/WordPress-Tabellen berühren.
6. Health, Home, Auth, DB, private Ressourcen und Security Header testen.
7. Ursache und Nacharbeiten in Incident-/Release-Notiz dokumentieren.

Additive Migrationen werden nicht blind „zurück-SQLt“. Datenbank-Rollback erfolgt über geprüftes Backup.
