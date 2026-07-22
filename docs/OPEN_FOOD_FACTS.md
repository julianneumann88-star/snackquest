# Open Food Facts

SnackQuest liest Produkte serverseitig aus API v3.6 anhand eines validierten EAN-13, EAN-8 oder UPC-A. Der Response wird auf eine kontrollierte Feldliste normalisiert; fehlende Communitydaten bleiben leer und werden nie erfunden.

```mermaid
flowchart TD
  C["Kamera / Bild / manueller Code"] --> V{"Checksumme gültig?"}
  V -- Nein --> E["klare Eingabemeldung"]
  V -- Ja --> K{"frischer Cache?"}
  K -- Ja --> P["Produktansicht"]
  K -- Nein --> O["OFF v3 · serverseitig"]
  O -->|Treffer| P
  O -->|kein Treffer| X["privates Custom Product"]
  O -->|degraded| S["stale Cache oder verständlicher Fallback"]
```

Schutz: individueller User-Agent, harte Timeouts, positiver/negativer Cache, konservatives Limit von 13 Produktabfragen pro Minute/IP, keine Suche beim Tippen. Quelle, ODbL/DbCL, CC BY-SA für Bilder und der Hinweis auf möglicherweise unvollständige Daten sind sichtbar.
