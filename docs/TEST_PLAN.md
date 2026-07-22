# Testplan

## Automatisiert

- PHP-Syntax und JS-Parser.
- EAN/UPC-Checksumme, OFF-Normalisierung und Missing Fields.
- Cross-User-Isolation, Bewertungsvalidierung, Profildaten, Elo-Persistenz.
- Share-Payload-Minimierung, Eigentümer-Widerruf und Token-Ungültigkeit.
- Public Pages, Security-/SEO-Metadaten, Auth-Redirects und API Health.
- Registrierung → Log-Mail-Verifizierung → Login → Onboarding.
- Zwei private Produkte mit Upload, Bewertung/Preis/Tags, Bibliothek, Sammlung, Duell, Profil, Export, Share/Widerruf, Kontolöschung.
- Desktop Chromium und Mobile Chromium, axe serious/critical, Overflow.

## Vor Produktion manuell

Google Login mit realem Client, SMTP-Zustellung, iPhone Safari Kamera/Installation, Android Chrome Kamera/Installation, Desktop-PWA, Live-OFF-Treffer/Fehltreffer, KI Opt-in/Timeout, Backup/Restore-Probe, Account-Delete-Dateien und Remote Security Header.
