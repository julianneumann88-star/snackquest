# Barcode-Scanning

Nach einem Nutzerklick fordert die App `getUserMedia` mit bevorzugter Rückkamera an. Unterstützt der Browser `BarcodeDetector`, werden EAN-13, EAN-8 und UPC-A nativ gelesen. Andernfalls lädt das gebündelte ZXing-Modul. Bilddateien werden lokal im Browser dekodiert; Frames werden nicht hochgeladen.

Bei verstecktem Tab, Seitenwechsel oder Stop wird der Kamera-Stream geschlossen. Ablehnung, fehlende Kamera, ungültige Checksumme und kein Treffer besitzen klare Fallbacks. Der manuelle Barcode-Eingang bleibt immer verfügbar. Kamera läuft nur über HTTPS und ist per Permissions Policy auf die eigene Origin beschränkt.
