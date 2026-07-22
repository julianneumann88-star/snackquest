# Authentifizierung und Google OAuth

E-Mail-Konten verwenden `password_hash`, Verifizierungs- und Reset-Tokens werden ausschließlich gehasht gespeichert und laufen ab. Rate Limits schützen Registrierung, Login und Reset. Nach Login wird die Session-ID regeneriert; Cookies sind `HttpOnly`, `Secure` in Produktion und `SameSite=Lax`.

```mermaid
sequenceDiagram
  participant U as Nutzer
  participant S as SnackQuest
  participant G as Google
  U->>S: Mit Google anmelden
  S->>S: state + PKCE verifier speichern
  S->>G: authorize(openid email profile, challenge)
  G-->>S: exakter Callback + code + state
  S->>G: code + verifier gegen Tokens tauschen
  S->>G: ID-Token/Claims validieren
  S->>S: verifiziertes Konto verknüpfen/anlegen
  S-->>U: neue Cookie-Session
```

Produktionswerte:

- Origin: `https://julian-neumann.org`
- Redirect: `https://julian-neumann.org/snackquest/auth/callback`
- Zielgruppe: extern
- Scopes: `openid`, `email`, `profile`

Client-Secret und Client-JSON bleiben ausschließlich in ignorierter lokaler bzw. produktiver Konfiguration. Bestehende CouchPilot-Clients werden nicht wiederverwendet oder verändert.
