# Privacy Engineering

Datensparsamkeit ist Standard: kein öffentlicher Feed, keine Werbung, kein Tracker, kein Session Replay, keine Analyse-Pipeline. Session-Cookie und lokale Offline-Drafts sind funktional notwendig. Google wird nur nach Auswahl kontaktiert; OFF erhält serverseitig nur den Barcode; KI erhält erst nach Opt-in aggregierte Profildaten.

Share-Snapshots schließen E-Mail, Nutzer-ID, Notiz, Preise und private Fotos technisch aus. Tokens haben 256 Bit Zufall und liegen nur als SHA-256-Hash vor. Kontoexport liefert strukturierte Nutzerdaten; Kontolöschung kaskadiert DB-Daten und entfernt Dateien. `bin/maintenance.php` löscht abgelaufene Tokens/AI-Caches, alte Rate-Limits, Auditereignisse nach 90 Tagen und App-Logs nach 30 Tagen.

Die öffentliche Datenschutzerklärung ist eine technische, produktbezogene Fassung und vor kommerziellem Betrieb juristisch zu prüfen.
