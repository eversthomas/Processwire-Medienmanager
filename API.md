# bsProcessMedienManager — Entwickler-Kurzreferenz

Kurzübersicht über das **Bundle** (Process-Modul, API, Traits, Inputfield, Fieldtype).

**Sicherheit:** [`SECURITY.md`](SECURITY.md) (Checkliste: Rechte, CSRF, Uploads, Logs).

## Verzeichnisaufbau

| Pfad | Rolle |
|------|--------|
| `bsProcessMedienManager.module.php` | Process: `execute*`-Routen, Rechte, Konfiguration; **dünne Fassade** |
| `lib/MediaManagerAjaxTrait.php` | JSON-Endpunkte für Admin-JS / Picker |
| `lib/MediaManagerRenderTrait.php` | Admin-HTML (Grid, Listen, Formulare) |
| `MediaManagerAPI.php` | Domänenlogik: Suche, Dateien, Kategorien, Bild/WebP |
| `InputfieldMedienManager.module.php` | Backend-Feld-UI (Modal-Picker) |
| `FieldtypeMedienManager.module.php` | Feldspeicher (Referenzen auf Medien-**Pages**) |
| `MedienManagerField.php` | `Field`-Unterklasse (`@property`-Doku) |
| `js/` · `css/` | Admin- und Inputfield-Assets |
| `SECURITY.md` | Sicherheits-Checkliste |

## Fieldtype „Medien (Manager)“ (Phase 7)

- **Speicher:** wie `FieldtypeMulti` — eine Zeile pro referenzierter `medienmanager-item`-Page (`data` = ID). Sortierung über `sort`-Spalte.
- **Laufzeitwert:** `PageArray` gültiger Medien-Pages (versteckte Items werden mit `include=all` geladen).
- **Vorlagen** (beim Anlegen eines neuen Feldes): u. a. *Einzelbild (wie Image)*, *Mehrere Bilder (wie Images)*, *Einzelmedium*, *Mehrere Medien* — setzen `maxItems` und `allowedTypes` (`bild` / `video` / `pdf`).
- **Feldkonfiguration:** Zusätzlich zu **maxItems** ein einklappbarer Block **PHP-Beispiele (Frontend)** mit kopierbaren Snippets (Alternativtext, `figure`/`figcaption`, Lazy Loading).

### Template-API (Frontend)

Der Feldwert ist immer ein **`PageArray`**. Pro Eintrag:

| Methode (`MediaManagerAPI`) | Zweck |
|-----------------------------|--------|
| `getPrimaryPageimage(Page)` | `Pageimage` oder `null` |
| `getPublicFileUrl(Page)` | Öffentliche URL (Bild/Datei) |
| `getAccessibleLabel(Page)` | Text für **`alt`** / Screenreader: `mm_alt`, sonst Titel |
| `getCaption(Page)` | `mm_caption` für **`figcaption`** (kann leer sein) |
| `hasRenderableImage(Page)` | Prüfung vor `<img>` |

**Konventionen (SEO / Barrierefreiheit):**

- `alt` immer aus `getAccessibleLabel()` (oder leer lassen nur bei rein dekorativem Bild — dann bewusst `alt=""`).
- Sinnvolle Ausgabe von Breite/Höhe (`width`/`height`) über die gewählte `Pageimage::size()`-Variation.
- `loading="lazy"` / `decoding="async"` für unter den Fold platzierte Medien.
- Optional: `<figure role="group">` + `getCaption()` als `<figcaption>`.

### Edge Cases

| Situation | Verhalten |
|-----------|-----------|
| Medien-Item wurde gelöscht | Referenz wird beim Speichern der **Seite** entfernt; bis dahin kann die ID in der DB stehen — `wakeup` lädt nur existierende Pages. |
| Kein Bild (nur Video/PDF) | `hasRenderableImage()` ist false; URL z. B. mit `getPublicFileUrl()`. |
| Frontend-Zugriff | Ausgabe-URLs sind normale PW-Datei-URLs; wer die URL kennt, kann die Datei abrufen (wie bei `Pagefile::url`). Feinrechte pro Rolle sind nicht Bestandteil dieses Feldes. |

## `MediaManagerAPI`

Instanziierung: `new MediaManagerAPI($wire)` — im Process-Modul über `api()`.

**Suche & Zugriff:** `findMedia()` (u. a. `typ`, `typs` für Mehrfach-Typfilter, `q`, `kategorie_id`), `getMediaItem()`, `getKategorien()`

**Anlegen / Ändern / Löschen:** `createMediaItem()`, `saveMediaItem()`, `deleteMedia()`, `duplicateMediaItem()`, `replacePrimaryFile()`

**Dateien & URLs:** `getPrimaryPageimage()`, `getPrimaryNonImageFile()`, `getPublicFileUrl()`, `getThumbnailUrlForSlot()`, `getPrimaryBasename()`, …

**Bildverarbeitung:** `createVariants()`, `rotateMasterPageimage()`, `resizeMasterPageimage()`, `ensureWebpForPageimage()`

**Kategorien:** `createKategorie()`, `deleteKategorie()`

**Installation:** `install()`  
**Deinstallation:** `uninstall()` leert nur die Modul-Konfiguration; Medien-Pages und Felder bleiben (siehe `README.md`).

## Admin-URLs (Process)

Basis: `{adminUrl}setup/medienmanager/`

| Pfad | Zweck |
|------|--------|
| `./` | Grid/Liste |
| `ajax/` | Zentraler AJAX (`action`); schreibende Aktionen nur **POST** + CSRF |
| `upload/` | Mehrfach-Upload (JSON) |
| `edit/` | Bearbeiten |
| `save/` | POST Speichern |
| `replace/` | Datei ersetzen |
| `imageedit/` | Bild drehen / Größe (Master-Datei) |
| `kategorien/` | Kategorien |
| `delete/` | Eintrag löschen |

## AJAX-Aktionen (`action`)

Lesezugriffe ohne CSRF. **Schreibende** Aktionen: Session-**CSRF** und nur **POST** (siehe `___executeAjax()`).

| `action` / Parameter | Kurzbeschreibung |
|----------------------|------------------|
| `modal-items` | Picker: HTML + Pagination; optional `allowed_types=bild,video` (Einschränkung mehrerer Typen) |
| `thumb` | Chip-Vorschau |
| … | (weitere wie in früherer Version) |


## Frontend-Mini-API (Phase 10)

Die Frontend-Mini-API ermöglicht die 1-Zeilen-Ausgabe von Medien im Template — inklusive responsiver `<picture>`-Pipeline, WebP-Varianten, Breakpoints, CLS-Schutz und barrierefreiem Alt/Caption-Handling.

Es ist **kein** manuelles `new MediaManagerAPI()` oder `require_once` im Template nötig.

### 1. Schnelle 1-Zeilen-Ausgabe

```php
// Automatisch responsives <picture> mit WebP, srcset und Fallback <img>
echo $page->mein_bild->render();
```

### 2. Ausgabe mit Optionen

```php
echo $page->mein_bild->render([
    'width'        => 1200,          // Ziel-Maximalbreite (Standard: 1200)
    'height'       => 600,           // Ziel-Höhe (0 = proportional)
    'crop'         => true,          // Zuschneiden ('center', 'north', etc.)
    'webp'         => true,          // Automatische .webp-Erzeugung (Standard: true)
    'picture'      => true,          // Als <picture> ausgeben (Standard: true; false = nur <img>)
    'widths'       => [480, 800, 1200], // Eigene Breakpoints für srcset
    'sizes'        => '(max-width: 768px) 100vw, 1200px', // Responsive sizes-Attribut
    'class'        => 'my-img-class',// CSS-Klasse für <img>
    'pictureClass' => 'my-picture',  // CSS-Klasse für <picture>
    'loading'      => 'lazy',        // 'lazy' (Standard) oder 'eager'
    'caption'      => true,          // Bildunterschrift (mm_caption in <figcaption>)
    'figureClass'  => 'my-figure',   // CSS-Klasse für <figure>
]);
```

### 3. Mehrfachauswahl / PageArray

```php
// Alle Medien eines Feldes auf einmal rendern:
echo $page->meine_galerie->render();

// Oder einzeln im Loop:
foreach($page->meine_galerie as $media) {
    echo $media->render(['width' => 800]);
}
```

### 4. Direkte Helper-Properties auf `$media`

Für individuelle Markup-Strukturen stehen direkte Properties auf der Medien-Page zur Verfügung:

| Property / Methode | Rückgabe | Beschreibung |
|--------------------|----------|--------------|
| `$media->mediaUrl` | `string` | Direkte öffentliche URL der Originaldatei |
| `$media->mediaUrl(w, h)` | `string` | URL einer skalierten Bildvariante (z. B. `$media->mediaUrl(800, 600)`) |
| `$media->alt` | `string` | Alternativtext (`mm_alt` mit Fallback auf Titel) |
| `$media->caption` | `string` | Bildunterschrift (`mm_caption`) |
| `$media->isImage` | `bool` | `true`, wenn das Medium ein Bild ist |
| `$media->isVideo` | `bool` | `true`, wenn das Medium ein Video ist |
| `$media->isPdf` | `bool` | `true`, wenn das Medium ein PDF ist |
| `$media->isSvg` | `bool` | `true`, wenn das Medium ein SVG ist |
| `$media->focus` | `array` | Focal-Point-Koordinaten `['top'=>float, 'left'=>float, 'css'=>string]` |
| `$media->focalUrl(w, h)` | `string` | Bildvariante gecroppt zentriert auf den Focal Point |
| `$media->usedOnPages` | `PageArray` | Alle Seiten im System, die dieses Medium referenzieren |
| `$media->usageCount` | `int` | Anzahl der referenzierenden Seiten |
| `$media->pageimage`| `Pageimage|null` | Natives ProcessWire `Pageimage`-Objekt für native Methoden |
| `$media->dimensions`| `string` | Z. B. `"1920 × 1080"` |
| `$media->filesize` | `string` | Z. B. `"2.4 MB"` |

## Asset-Intelligence & Redaktionskomfort (Phase 11)

### 1. Verwendungsnachweis („Used on pages“)
Medien können im gesamten ProcessWire-System rückwärts aufgespürt werden:
- **API-Methoden:**
  - `$api->getReferencingPages($media)`: Liefert alle Pages (`PageArray`), die dieses Medium in einem `FieldtypeMedienManager` oder `FieldtypePage`-Feld referenzieren.
  - `$api->getAllMediaUsageCounts()`: Gibt ein assoziatives Array `[media_id => count]` zurück.
  - `$api->isMediaInUse($media)`: Schnellabfrage, ob das Medium aktiv eingebunden ist.
- **Backend-Schutz:**
  - In Grid- und Listenansicht zeigt ein Badge die Anzahl der Verwendungen (`fa-link`).
  - Im Bearbeiten-Formular listet eine Infobox alle referenzierenden Seiten mit Direktlinks zum Admin-Editor.
  - **Löschschutz:** Wird ein referenziertes Medium gelöscht (Einzeln oder Bulk), fordert ein Sicherheitsdialog mit Namensauflistung der betroffenen Seiten eine ausdrückliche Bestätigung an (`force=1`).

### 2. „Unbenutzte Medien“-Filter
- In der Toolbar der Medienübersicht filtert das Dropdown **„Alle Medien“ / „Nur unbenutzte“ / „Nur verwendete“** verwaiste Assets in Sekundenschnelle heraus (`usage=unused`).
- Über die Bulk-Auswahl können unbenutzte Assets mit einem Klick bereinigt werden.
- In der API: `$api->findMedia(['usage' => 'unused'])`.

### 3. Focal Point (Intelligenter Crop)
- **Visueller Reticle-Editor:** Im Bildbearbeitungs-Dialog (`imageedit`) kann per Klick oder Drag ein Fokuspunkt auf das Hauptmotiv gesetzt und gespeichert werden.
- **Persistierung:** Wird über ProcessWires natives `Pageimage::focus($top, $left)` in `filedata['focus']` gespeichert.
- **Frontend & CSS:**
  - `$media->focus`: Liefert `['top' => float, 'left' => float, 'css' => 'X% Y%']`.
  - `$media->focalUrl($w, $h)`: Erzeugt eine per Focal Point gecroppte Bildvariante.
  - `$media->render(['focus_css' => true])`: Fügt `style="object-position: X% Y%"` an das `<img>` an, sodass auch CSS `object-fit: cover` das Hauptmotiv immer im Fokus behält.

### 4. Sichere SVG-Unterstützung
- Vektorgrafiken (`.svg`) werden wie reguläre Bilder hochgeladen und verwaltet.
- **XML/Script-Sanitizer (`MediaManagerAPI::sanitizeSvgFile()`):**
  - Blockiert und neutralisiert Stored XSS, `<script>`-Tags, Event-Handler (`onload`, `onerror`, `onclick` etc.), `javascript:`-URIs und `<foreignObject>`.
  - Schützt vor XML External Entity (XXE) Injection und DoS (`<!ENTITY`, `SYSTEM`).
  - Fehlgeschlagene Sanitizations werden protokolliert (`medienmanager.log`) und der Upload abgebrochen.
- **Frontend:** `$media->render()` gibt SVGs automatisch als valides `<img>` mit Originaldimensionen aus viewBox aus (ohne GD-Resize-Fehler).

## Berechtigung

`medien-manager` — siehe Modul `permissions` in `getModuleInfo()`.

## Modul-Konfiguration

Zusätzlich zu **Grid-Items pro Seite**: **Max. Upload-Größe pro Datei (MB)** — obere Kappe neben `php.ini` (`upload_max_filesize` / `post_max_size`). `0` = nur php.ini.

## Version

Siehe `getModuleInfo()['version']` — bei Updates **Module aktualisieren**, damit `___upgrade` läuft.
