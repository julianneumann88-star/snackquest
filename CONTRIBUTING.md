# Beitragen

1. Keine realen Secrets, Produktionsdaten, persönlichen Pfade oder privaten Infrastrukturdetails einchecken.
2. Änderungen auf einem Branch vornehmen und `npm ci`, `npm run build`, `npm run lint`, `npm run audit:secrets`, `npm test` sowie bei UI-/Flow-Änderungen `npm run test:e2e` ausführen.
3. Datenbankänderungen als neue, additive Migration für MariaDB und SQLite ergänzen.
4. Jede private Abfrage muss `user_id`/Eigentum serverseitig prüfen. Client-Checks gelten nie als Autorisierung.
5. Produktdatenquellen, Lizenzen und Datenschutzgrenzen sichtbar halten. Keine Demo-Daten oder Marketingbehauptungen erfinden.

Security-Probleme bitte nicht öffentlich melden; siehe [SECURITY.md](SECURITY.md).
