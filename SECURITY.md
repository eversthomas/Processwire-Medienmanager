# Sicherheit — Medien Manager (Checkliste)

Kurzüberblick für Reviews und Betrieb. Ergänzend: [`API.md`](API.md) (technische Endpunkte).

## Zugriff und Authentifizierung

| Maßnahme | Umsetzung |
|----------|-----------|
| Admin-Oberfläche und AJAX | Berechtigung **`medien-manager`** (`_requirePermission()` auf allen `execute*`-Einstiegen) |
| Mutierende AJAX-Aktionen | **CSRF**-Token (`_validateCsrf()`), nur **POST** |
| Lese-AJAX (Picker: `modal-items`, `thumb`, `kategorien`) | Eingeloggt + (**`medien-manager`** oder **`page-edit`**) — damit Seiten-Redakteure den Picker nutzen können; GET ohne CSRF (keine Zustandsänderung) |
| Löschschutz | Prüfung auf aktive Verknüpfungen in `FieldtypeMedienManager`/`FieldtypePage`; Bestätigungspflicht (`force=1`) vor Entfernung referenzierter Medien |
| Diagnose **`/test/`** | Nur **Superuser**, JSON ohne GET/POST-**Werte** (nur Schlüssel) |

## Eingaben und Uploads

| Maßnahme | Umsetzung |
|----------|-----------|
| Dateitypen Upload | Positivliste Dateiendungen (Bilder, Video, PDF, SVG) |
| SVG-Upload-Sanitizer | Pure-PHP-XML-Parser in `MediaManagerAPI::sanitizeSvgFile()`: Strip von `<script>`, Event-Handlern (`on*`), `javascript:`, `<foreignObject>` und Blockierung von XXE/DOCTYPE-Entities |
| Dateigröße | Zusätzlich zu PHP: effektives Maximum = min(`upload_max_filesize`, `post_max_size`) und optional **Modul-Konfiguration** „Max. Upload … MB“ |
| Hochgeladene Dateien | Nur über `is_uploaded_file` / ProcessWire-API; keine Ausführung von Inhalten |
| Bulk-Aktionen | Max. **500** IDs pro Request (Schutz vor überlangen Payloads) |
| Selektoren / Texte | ProcessWire-**Sanitizer** wo sinnvoll |

## Logs und JSON

| Maßnahme | Umsetzung |
|----------|-----------|
| Datei-Log `medienmanager` | Normale Meldungen nur bei **Superuser**; Fehler mit `$force` immer |
| Fehlermeldungen an Browser | Keine Server-Pfade in JSON-Erfolgsmeldungen; generische Texte bei Fehlern |

## Offene Punkte / Betrieb

- **`php.ini`**: `upload_max_filesize` und `post_max_size` für erwartete Dateigrößen setzen  
- **HTTPS** im Admin: übliche ProcessWire-Empfehlung  
- **Backups** vor größeren Datenmigrationen  

## Manuelle Smoke-Checkliste (nach Update)

Kurztest im Admin — jeweils mit Rolle, die `medien-manager` hat (Picker zusätzlich mit `page-edit`):

| # | Check | Erwartung |
|---|--------|-----------|
| 1 | Modul aktualisieren (nicht nur Refresh) | Version **2.5.1**, keine PHP-Fehler |
| 2 | Medien Manager öffnen (Raster + Liste) | Grid/Liste laden, Filter sichtbar |
| 3 | Eine Bilddatei + eine SVG hochladen | Items erscheinen; SVG ohne Script-Reste nutzbar |
| 4 | Medium bearbeiten, speichern | Metadaten bleiben; CSRF ok |
| 5 | Fokuspunkt setzen und speichern | Werte bleiben; Toast/Erfolg |
| 6 | Medium auf einer Seite per Feld zuweisen | Picker lädt; Speichern der Seite ok |
| 7 | Filter **Nur unbenutzte** / **Nur verwendete** | Zugewiesenes Medium unter „verwendet“; nach Entfernen der Referenz unter „unbenutzt“ (auch bei Page-Reference-Feldern auf `medienmanager-item`) |
| 8 | Löschen eines verwendeten Mediums | Warnung mit Seitenliste; erst mit Bestätigung weg |
| 9 | Bulk: Kategorie setzen / WebP (Bilder) | Erfolgsmeldung; keine CSRF-Fehler |
| 10 | Frontend: `$page->feld->render()` | `<picture>`/Bild bzw. Video/PDF-Link wie zuvor |
| 11 | TinyMCE pwimage: Seite mit Medien-Manager-Feld (Bilder) + Textfeld | Zugewiesene Primärbilder erscheinen in der Bildauswahl; Einfügen + Speichern ok |

*Bei neuen Features diese Liste und die Tabellen oben mitpflegen.*
