# PWA und Offline-Verhalten

Manifest, 192-/512-Pixel-Icons, Maskable Icon, Apple Touch Icon, Theme Color und Service Worker ermöglichen Installation auf unterstützten Desktop- und Mobilbrowsern. Der Install-Hinweis erscheint nicht beim ersten Besuch.

Der Service Worker cached nur öffentliche App-Shell-Assets und die Offline-Seite. `/app`, `/auth`, `/api`, `/media`, Login/Registrierung und sämtliche Responses mit `private`/`no-store` werden nie gecacht. Offline-Bewertungsentwürfe liegen in einer pro Nutzer-ID getrennten IndexedDB, synchronisieren bei der nächsten Online-Session mit CSRF und werden vor Logout gelöscht.

iOS ohne Install-Prompt: Teilen → „Zum Home-Bildschirm“. Kamera und Service Worker benötigen HTTPS.
