# Open Prices

Die aktuelle Open Prices API wurde geprüft. SnackQuest v1 speichert echte persönliche Preise und Kauforte privat in MariaDB. Es werden keine automatischen Preisbeiträge an Open Prices gesendet.

Die Konfiguration besitzt einen standardmäßig deaktivierten Read-Schalter. Eine spätere öffentliche Preisabfrage muss separat mit Cache, Quellennachweis, Länder-/Währungsprüfung, degraded state und der offiziellen API-Dokumentation umgesetzt und getestet werden. Ein Ausfall darf die private Preisfunktion nie beeinträchtigen.

Primärquellen: <https://prices.openfoodfacts.org/api/docs> und <https://openfoodfacts.github.io/open-prices/>.
