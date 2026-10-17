# Dateien

Die Fork-Extension `extensions/Dateien/` ergänzt eine interne Dateiablage. Sie wird automatisch geladen, ist als Modul **standardmäßig ausgeschaltet** und benötigt keine Änderungen am Upstream.

## Einrichtung und Bedienung

1. Unter **Admin → Module** „Dateien“ einschalten und den gewünschten Mitgliedern freigeben. Die Freigabe allein gewährt noch keinen Zugriff auf eine Ablage.
2. Als globaler Admin unter **Admin → Dateien** (`/admin/dateien`) eine Ablage anlegen. Optional einen Standort und ein Kontingent in MiB wählen. Ohne Standort ist eine Ablage standortübergreifend; ein Kontingent umfasst **alle Versionen einschließlich Papierkorb**, nicht nur die aktuellen Inhalte.
3. Dort eigene Dateigruppen anlegen und bestehende Mitglieder zuordnen. Dateigruppen sind unabhängig von Abteilungen und Funktionen. Die Modulrolle `gruppenverwalter` erlaubt Gruppen- und Mitgliederverwaltung, aber keinen automatischen Zugriff auf Dateien. Das Gruppenverwalter-Kennzeichen einer Mitgliedschaft dokumentiert die Zuständigkeit; es ersetzt die Modulrolle nicht.
4. Unter `/dateien` die Ablage öffnen und Rechte an Benutzer oder Dateigruppen vergeben. Globale Admins dürfen in aktiven Modulen alles; für alle anderen gilt **standardmäßig kein Zugriff**.

Der Explorer bietet Ordner, Einzel-Upload, Umbenennen, Verschieben innerhalb einer Ablage, Downloads, Versionsverlauf, Rechte und Papierkorb. Namenskonflikte werden angezeigt; es gibt kein stilles Überschreiben. Für einen Inhaltswechsel ausdrücklich eine **neue Version** hochladen. „Mit mir geteilt“ erschließt Direktfreigaben, auch wenn der übergeordnete Ordner nicht zugänglich ist.

## Rechte

| Recht | Befugnisse |
|---|---|
| `read` – Lesen | Auflisten, ansehen, herunterladen, Versionsverlauf lesen |
| `edit` – Bearbeiten | Zusätzlich hochladen, Ordner erstellen, umbenennen, neue Versionen und Versionswiederherstellungen |
| `manage` – Verwalten | Zusätzlich Rechte/Vererbung ändern, verschieben, Papierkorb und Wiederherstellung, endgültig löschen |

Rechte gelten für eine ganze Ablage oder einen einzelnen Ordner/eine Datei. Benutzer- und Gruppenrechte werden zusammengeführt; das **höchste zutreffende Recht** gewinnt. Es gibt keine expliziten Verbote. Neue Einträge erben Rechte vom Elternordner beziehungsweise der Ablage. Wird die Vererbung an einem Eintrag unterbrochen, zählen dort nur dessen eigene Rechte und für dessen Nachkommen die Rechte unterhalb dieser Grenze.

Eine Direktfreigabe verrät keine Namen gesperrter Eltern oder Geschwister. Ein Ordnerrecht öffnet nicht automatisch Kinder mit unterbrochener Vererbung. Verschieben erfordert `manage` an Quelle **und** Ziel; es kann die geerbten Rechte verändern. Ablageübergreifendes Verschieben ist in diesem Meilenstein absichtlich ausgeschlossen.

### Standorte

Bei aktiver Locations-Extension prüft die zentrale Berechtigungslogik zusätzlich den Standort der Ablage. Auch ein direktes Benutzerrecht oder eine Gruppenmitgliedschaft umgeht diese Grenze nicht. Das gilt für technische Downloads und Service-Aufrufe, nicht nur für die Oberfläche. Gruppenverwalter sehen nur gemeinsame Gruppen und Gruppen ihrer Standorte und dürfen keine fremden Standortmitglieder durch manipulierte IDs hinzufügen. Globale Admins verwalten alle Standorte. Standortlose Ablagen und Gruppen sind bewusst gemeinsam – für vertrauliche Standortdaten immer einen Standort setzen.

## Versionierung und Papierkorb

- Jede neue Datei beginnt mit Version 1. Inhaltsänderungen hängen eine neue Version an; bestehende Versionsmetadaten sind durch einen PostgreSQL-Trigger gegen Updates geschützt.
- Eine alte Version wiederherzustellen erzeugt eine neue aktuelle Version. Der Verlauf wird nicht zurückgesetzt.
- Jede Version trägt SHA-256-Prüfsumme, Bytezahl, erkannten MIME-Typ, Endung, Ersteller und Herkunft. Identische erneute Verarbeitung des aktuellen Inhalts erzeugt keine Doppelversion.
- Änderungen an einer Ablage werden innerhalb einer Datenbanktransaktion gesperrt. Versionsaktionen unterstützen zusätzlich eine erwartete Versionsnummer: Ist der Stand veraltet, wird die Änderung mit einem Konflikt abgelehnt statt fremde Änderungen unbemerkt zu ersetzen.
- Löschen verschiebt in den Papierkorb. Inhalte unter einem gelöschten Ordner sind nicht mehr lesbar, auch nicht über frühere Direktfreigaben. Vor der Wiederherstellung muss der Elternordner wieder verfügbar sein; ein inzwischen belegter Name verhindert die Wiederherstellung.
- Endgültiges Löschen ist eine eigene bestätigungspflichtige Aktion mit `manage`. Die konfigurierbare Mindestaufbewahrung (Standard: 30 Tage) muss abgelaufen sein. **Es gibt keine automatische Löschung**.

## Datenmodell

Alle Tabellen verwenden `x_files_`:

| Tabelle | Inhalt |
|---|---|
| `spaces` | Ablagen, optionaler Standort, Kontingent |
| `nodes` | Datei-/Ordnerbaum, Vererbungsgrenze, Papierkorb |
| `versions` | Unveränderliche Versionsmetadaten und privater Speicherschlüssel |
| `groups`, `group_members` | Dateigruppen und bestehende Benutzer mit Zuständigkeitskennzeichen |
| `permissions` | Benutzer-/Gruppenrechte auf Ablagen oder Einträgen |
| `audit_events` | Aktionen und IDs, ohne Dateibytes oder Speicherzugangsdaten |

Fremdschlüssel, Prüfungen und eindeutige aktive Namen pro Ordner sichern die Struktur zusätzlich zur zentralen Service-Schicht. Favoriten sind nicht Teil dieses Meilensteins.

## Betrieb und Sicherheit

- Dateien liegen auf dem privaten Laravel-Disk `dateien` unter **`storage/app/private/dateien`**, niemals unter `public/` oder dem Storage-Symlink. Die Pfade bestehen aus generierten Schlüsseln, nicht aus eingegebenen Dateinamen.
- Dieses Verzeichnis muss persistent, für den PHP-Prozess schreibbar und auf allen App-Instanzen konsistent verfügbar sein. Standard ist ein lokales Dateisystem mit atomarem Umbenennen. Andere Storage-Treiber sind in diesem Meilenstein nicht unterstützt.
- **Datenbank und Dateiablage zusammen sichern und zusammen wiederherstellen.** Ein Datenbankbackup allein enthält keine Datei-Inhalte. Beim Backup Schreibzugriffe anhalten oder einen konsistenten gemeinsamen Snapshot erstellen.
- Uploads durchlaufen temporäres Schreiben, Typ-/Größenprüfung und atomare Veröffentlichung. Normale Fehler räumen temporäre beziehungsweise noch nicht referenzierte neue Inhalte auf. Ein harter Prozess-/Hostabbruch kann verwaiste Dateien hinterlassen: Speicher überwachen; unbekannte Dateien niemals allein anhand ihres Alters ohne Abgleich mit `x_files_versions` löschen.
- Endgültige Löschungen verwenden eine persistente Löschliste (`x_files_pending_deletions`): Erst nach erfolgreichem Datenbank-Commit werden Dateibytes entfernt. Bei Speicherfehlern bleiben die Aufträge erhalten. Globale Admins können unter **Admin → Dateien → Speicherbereinigung** ausstehende Löschungen erneut versuchen; weitere endgültige Löschaktionen arbeiten die Liste ebenfalls ab.
- Uploadlimit standardmäßig 20 MiB, administrativ bis maximal 50 MiB. Erlaubt sind nur ausgewählte Endungen aus der zentralen `FileResponse`-Allowlist und passende MIME-Typen. PHP (`upload_max_filesize`, `post_max_size`), Livewire-Temporärupload und Reverse Proxy können kleinere Grenzen setzen und müssen zum gewünschten Limit passen.
- Office-Dateien werden nur gespeichert/heruntergeladen, nicht serverseitig ausgeführt. Die Typprüfung ersetzt **keinen Virenscanner**. Dateien nur aus vertrauenswürdigen Quellen öffnen; ein Virenscanner ist nicht integriert.
- Downloads benötigen Anmeldung, aktives Konto, Modulzugriff, Standortzugriff und ACL. Versions-IDs werden an die angefragte Datei gebunden. Sichere Dateiauslieferung erfolgt über `FileResponse` mit `private, no-store` und `nosniff`; es gibt keine öffentlichen Datei-URLs.
- Datei-Inhalte, Tokens und Speicherzugangsdaten gehören nicht in Logs. Die Extension führt eigene Metadaten-Auditereignisse; der allgemeine ActivityRecorder verarbeitet keine Extension-Modelle.
- Die Dateien-Oberfläche und Downloads sind kein Offline-Angebot. Keine Dateien in Service-Worker- oder Proxy-Caches aufnehmen.

## Tests und Grenzen

Pest-Featuretests liegen unter `tests/Feature/Extensions/Dateien/` und benötigen PostgreSQL wie die übrigen Featuretests:

```bash
./vendor/bin/pest tests/Feature/Extensions/Dateien
./vendor/bin/phpstan analyse -c extensions/Dateien/phpstan.neon --memory-limit=2G
```

Geprüft werden Modulfreigabe, Benutzer-/Gruppenrechte, Vererbungsgrenzen, Direktfreigaben ohne Metadatenlecks, Versionierung und Wiederherstellung, Namens-/Versionskonflikte, Papierkorb, IDOR-Schutz und Standortgrenzen.

Nicht enthalten: externe Freigabelinks, OnlyOffice/Collabora, WOPI/JWT/Office-Sessions, Volltextindex, WebDAV, Desktop-Sync, Offline-Bearbeitung und Favoriten. Für den nächsten Meilenstein sind Office-Anbindung und deren separate Sicherheits-/Betriebsanforderungen zu planen; dieser Meilenstein stellt dafür noch keine öffentlichen Bearbeitungsendpunkte bereit.
