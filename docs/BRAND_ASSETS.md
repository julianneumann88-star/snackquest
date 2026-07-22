# SnackQuest Markenassets

Die aktuelle SnackQuest-Marke verbindet eine als `Q` geformte Snackverpackung mit Scanrahmen und Bewertungs-Punkt. Sie wurde für eine klare Silhouette von Favicon-Größe bis Store-Icon entwickelt und verwendet ausschließlich die bestehende SnackQuest-Palette.

## Quelle und Build

- Master mit Transparenz: `src/brand/snackquest-mark-master.png`
- Deterministische Ableitungen: `npm run build:assets`
- Header-/Wortmarke: `public/assets/brand/snackquest-mark-256.png`
- PWA-/Store-Icons: `public/assets/icons/`
- Social Preview für Web: `public/assets/images/og-snackquest.png`
- Social Preview für GitHub (1280×640): `public/assets/images/github-social-preview.png`

Der Build erzeugt Standard-, Apple-, Maskable- und Monochrome-Icons mit jeweils passenden Sicherheitsabständen. Das Open-Graph-Bild wird ebenfalls aus demselben Master erzeugt. Dadurch bleiben Website, installierte PWA, Android-/iOS-Pakete, GitHub und Social Sharing konsistent.

## Generierungsprompt

Die Marke wurde mit der eingebauten Bildgenerierung erstellt. Kurzfassung des finalen Prompts:

> Originales, kompaktes SnackQuest-Symbol: ein kräftiges, abgerundetes `Q` aus einer vereinfachten Snackverpackung, kombiniert mit vier Barcode-Scan-Ecken und einem kleinen Bewertungs-Punkt. Flache, vektorfreundliche Grafik mit kräftiger Kontur in Near-Black, Citrus-Orange, Lime und Ivory; keine Schrift, keine Verläufe, keine Schatten, kein Maskottchen und keine generischen KI-Elemente.

Die erzeugte Chroma-Key-Fassung wurde lokal freigestellt und als transparenter Projekt-Master gesichert. Signaturdateien und Store-Zugangsdaten sind keine Markenassets und bleiben außerhalb des Repositorys.
