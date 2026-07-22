# Eigentümerisolation und Storage

„RLS“ wird in diesem MariaDB-Stack als zwingende serverseitige Eigentümerprüfung umgesetzt:

- Jede private Select-/Update-/Delete-Operation enthält `user_id=:u` oder einen `EXISTS`-Join zur nutzereigenen Oberressource.
- Collection Items werden nur über eine dem Nutzer gehörende Collection verändert.
- Shares werden nur vom Ersteller widerrufen; öffentlich auflösbar ist allein der Hash eines 256-Bit-Zufallstokens.
- Media-Routen prüfen `user_id`, bevor private Dateien gelesen werden.
- Cross-User-Unit- und E2E-Tests prüfen IDOR-Schutz.

Uploads liegen außerhalb von `public/`, erhalten Zufallsnamen, werden per MIME und Größe validiert, mit GD neu als WebP kodiert und dadurch von EXIF-/Metadaten befreit. Direkte Verzeichnisauflistung und Ausführung sind gesperrt.
