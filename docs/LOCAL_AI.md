# Lokale KI

Die KI ist optional, standardmäßig aus und für keine Kernfunktion nötig. Produktion verbindet ausschließlich serverseitig mit einer privaten OpenAI-kompatiblen GPT-OSS-Bridge.

```mermaid
flowchart TD
  A{"Bridge konfiguriert?"} -- Nein --> D["deterministisches Profil bleibt verfügbar"]
  A -- Ja --> O{"Nutzer-Opt-in?"}
  O -- Nein --> D
  O -- Ja --> R{"mindestens 3 Bewertungen?"}
  R -- Nein --> D
  R -- Ja --> G["Aggregate: Anzahl, Mittelwert, Wiederkauf, Top-Kategorien/-Marken/-Tags"]
  G --> L["lokale GPT-OSS-Bridge"]
  L -->|Erfolg| C["30 Tage gecachte Formulierung"]
  L -->|Fehler/Timeout| D
```

Ausgeschlossen sind E-Mail, Anzeigename, freie Notizen, Einzelpreise, Kauforte, Fotos und interne IDs. API-Key und Bridge-Details werden nie an den Browser ausgeliefert oder geloggt. Die Ausgabe darf keine Gesundheitsbehauptungen oder nicht aus den Aggregaten ableitbare Fakten erfinden.
