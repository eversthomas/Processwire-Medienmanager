# Phasenplan — bsProcessMedienManager

Dieses Dokument beschreibt die **geplante Reihenfolge** von Verbesserungen und Erweiterungen.  
Änderungen am Code erfolgen **nur** in diesem Modulordner (sofern nicht ausdrücklich anders vereinbart).

---

## Ablauf pro Schritt (Qualitätssicherung)

1. **Umsetzung** eines beschriebenen Teilschritts im Code.
2. **Kurze Testanleitung** (vom Entwickler geliefert) — manuelle Checks im Admin / ggf. erwartetes Verhalten.
3. **Du testest** und bestätigst („Schritt OK“ o. Ä.).
4. **Erst dann** wird der entsprechende Eintrag hier als **erledigt** markiert (`[x]`).
5. Anschließend startet der **nächste** Teilschritt bzw. die nächste Phase.

> Hinweis: Diese Datei wird bei Bestätigung aktualisiert (`[ ]` → `[x]`). Kein automatisches Überspringen von Schritten.

---

## ProcessWire vs. externe PHP-Bibliotheken — strategische Einordnung

**Kurzantwort:** Der Großteil der Ziele lässt sich **mit der ProcessWire-API und PW-Konventionen** umsetzen (Pages, Felder, Files, `Pageimage`, ImageSizer-Engine, Sessions, CSRF, Selektoren). Für ein **wartbares, PW-natives** Modul ist das die bevorzugte Basis.

**Wo PW ausreicht (typisch ohne zusätzliche Libs):**

| Bereich | PW-Mittel |
|--------|-----------|
| Medien als Pages, Metadaten, Zugriffsrechte | `Pages`, `Fields`, Templates, `User`/`Permission` |
| Bilder skalieren, zuschneiden, drehen | `Pageimage`, `$image->size()`, `$image->rotate()`, Konfiguration (`$config->imageSizerOptions` etc.) |
| Datei-Upload | `Pagefiles`, `Pagefile`, bestehende Upload-Pfade im Modul |
| Suche / Filter | Selektoren, `$pages->find()` |
| Mehrfach-Upload (serverseitig) | Mehrere Einträge in `$_FILES` oder sequentielle Requests — reines PHP + PW |
| CSRF, Session | `$session->CSRF` |

**Wo externe, gut gepflegte PHP-Bibliotheken **optional** Sinn machen können:**

| Bedarf | PW allein | Optional extern |
|--------|-----------|-----------------|
| **Komplexe Bildoperationen** (z. B. fortgeschrittene Filter, EXIF-Schreiben, spezielle Formate) | Basis: ImageSizer/GD oder Imagick über PW | `intervention/image` o. Ä. — nur wenn PW-API an Grenzen stößt und ihr klare Anforderungen habt |
| **PDF-Thumbnails / Vorschau** | Nicht Kern-PW; oft `exec` zu `ghostscript`/`imagick` oder schlanke Wrapper | z. B. dedizierte PDF-Libs **nur** wenn Server-Politik und Hosting das erlauben |
| **Video-Metadaten / Dauer** | Kein Standard in PW | `getID3` o. ä. — nur bei konkretem Bedarf |
| **HTTP-Client** (externe APIs) | `WireHttp` | Für OAuth2/REST oft `symfony/http-client` — nur wenn WireHttp nicht reicht |

**Empfehlung für dieses Modul:**

1. **Zuerst** alles mit **ProcessWire-Methoden** und sauberem **modernen OOPHP** (typisierte Eigenschaften, klare Services im Modul, keine Mischung von Concerns) umsetzen.
2. **Externe Composer-Pakete** nur einführen, wenn eine Anforderung **nachweislich** nicht sinnvoll mit PW erfüllbar ist oder Wartung/Security (z. B. geprüfte Krypto-Libs) es erzwingt — und dann **minimal** (eine klar abgegrenzte Abhängigkeit, Versionspin).
3. Kein „Framework im Modul“ ohne Bedarf; das Modul soll **in typische PW-Installationen** ohne Überraschungen laufen.

**Querschnitt (bei jeder größeren Änderung mitdenken):**

- **Modularisierung:** klare Grenzen zwischen API, Admin-UI, AJAX, Fieldtype(s) — siehe Phase 4.
- **Sicherheit:** nicht nur „funktioniert“, sondern absichtlich gehärtet — siehe Phase 6.

---

## Legende

- `[ ]` offen  
- `[x]` erledigt und von dir bestätigt  
- Abhängigkeiten: „Nach:“ verweist auf vorherige Schritte

---

## Phase 0 — Grundlagen stabilisieren (technische Schulden)

**Ziel:** Korrektes Verhalten bei Bild vs. Datei, Speicherung/Installation konsistent mit UI, Konfiguration wirksam, keine toten Debug-Endpunkte ohne Absicherung.

| # | Schritt | Beschreibung | Nach |
|---|---------|--------------|-----|
| 0.1 | `[x]` | **Primärmedium-Logik:** Zentrale Hilfsmethoden (z. B. in `MediaManagerAPI`): primäres Bild (`mm_bild`) vs. Datei (`mm_datei`); alle Stellen im Modul (Grid, Edit, Imageedit, Inputfield-Chips) nutzen dieselbe Logik — keine falschen Vorschauen/Bearbeitung am falschen Feld. | — |
| 0.2 | `[x]` | **Felder & Speichern:** Fehlende Felder in `install` ergänzen (`mm_tags`, `mm_beschreibung` o. Ä., falls im UI verwendet); `saveMediaItem` und `createMediaItem` speichern alle im Formular/Upload übergebenen Metadaten konsistent (inkl. Tags, Typ wo sinnvoll). | 0.1 |
| 0.3 | `[x]` | **Modulkonfiguration `gridLimit`:** Wert aus Modulconfig lesen und für Grid + AJAX (`modal-items`) verwenden; sinnvolle Defaults. | 0.1 |
| 0.4 | `[x]` | **Admin-JS Bildbearbeitung:** URL zur `imageedit`-Action korrekt (kein falsches `../..`); idealerweise Basis-URL aus PHP-Config (`_injectJsConfig` o. Ä.), damit relative Pfade nicht brechen. | 0.1 |
| 0.5 | `[x]` | **Aufräumen / harte Kanten:** `executeTest` entfernen oder nur für Superuser + klares Kennzeichen; Logging in `_injectJsConfig` entschärfen (kein unnötiges CSRF-Logging). | — |

**Phase 0 abgeschlossen, wenn:** Medien können angelegt, bearbeitet, im Grid und im Picker konsistent angezeigt werden; Bildbearbeitung greift auf das richtige Bild; Konfiguration wirkt.

---

## Phase 1 — Upload & Performance

**Ziel:** Produktiver Umgang mit vielen Dateien und klaren Vorschau-Größen.

| # | Schritt | Beschreibung | Nach |
|---|---------|--------------|-----|
| 1.1 | `[x]` | **Mehrfach-Upload (Server):** Ein Request mit mehreren Dateien oder dokumentierte sequentielle Strategie; einheitliche Fehlerantwort (JSON). | Phase 0 |
| 1.2 | `[x]` | **Mehrfach-Upload (UI):** Mehrere Dateien wählen; Fortschritt; Fehler pro Datei anzeigen. | 1.1 |
| 1.3 | `[x]` | **Thumbnail-Konstanten:** Ein zentrales Set an Größen (Grid, Picker, Liste), überall `Pageimage::size()` / bestehende Variationen; unnötige Neuberechnung vermeiden (z. B. feste Namenskonvention / eine Variation pro „Slot“). | Phase 0 |
| 1.4 | `[x]` | **Lazy-Loading:** Entweder natives `loading="lazy"` konsequent oder `data-src` + Observer — nicht beides widersprüchlich. | 1.3 |

---

## Phase 2 — Redakteurs-Features (ohne WordPress nachzubauen)

**Ziel:** Arbeiten wie in einer professionellen Mediathek: Metadaten, Bulk, Komfort — ergänzt um **UIkit 3**-konsistente Oberfläche für Redakteure (Schritte 2.5 ff.).

| # | Schritt | Beschreibung | Nach |
|---|---------|--------------|-----|
| 2.1 | `[x]` | **Alternativtext / Beschriftung:** Felder + Anzeige im Grid/Edit/Picker wo sinnvoll. | Phase 0 |
| 2.2 | `[x]` | **Bulk-Aktionen:** Mehrfachauswahl im Grid; Kategorie setzen; Löschen mit Bestätigung. | 1.x sinnvoll, mindestens Phase 0 |
| 2.3 | `[x]` | **„URL kopieren“** für die öffentliche Datei-URL (Clipboard-API + Fallback). | Phase 0 |
| 2.4 | `[x]` | **Duplizieren / Ersetzen** (optional getrennt): Duplikat-Page; oder Datei ersetzen mit Hinweis zu Referenzen (Fieldtype hält IDs). | 2.2 |
| 2.5 | `[x]` | **Grid-Karten & Vorschau:** Freischwebende Aktions-Icons durch **Hover-Overlay auf der Karte** ersetzen (halbtransparenter Hintergrund, zentrierte Aktionen). **Primär-Label:** Dateiname (aus primärer `Pagefile`/`Pageimage`), nicht numerische Page-ID. **Sekundärinfo:** Abmessungen (wo sinnvoll, z. B. Bild) und Dateigröße. **Einheitliches Kachelformat:** `uk-cover` bzw. CSS `object-fit` / festes Seitenverhältnis — konsistent zum restlichen UIkit-Layout. | 2.1–2.4 |
| 2.6 | `[x]` | **Bearbeiten-Ansicht (UIkit Grid):** Zwei Spalten — links **sticky** Bildvorschau, rechts Formularfelder. **Titel:** bei leerem/neuem Eintrag sinnvoll mit **Originaldateiname** vorbelegen. **Alternativtext:** Placeholder editorfreundlich formulieren (z. B. Bild für Screenreader und SEO beschreiben). | 2.1 |
| 2.7 | `[x]` | **Bulk-Leiste (UIkit):** Statt isoliertem Block eine **visuell verbundene** UIkit-Leiste (Toolbar/Card/Utility-Klassen). Zähler **live** im Muster **„3 von 12 ausgewählt“** (Auswahl vs. Treffer auf der aktuellen Seite / sinnvoll definiertes Total). | 2.2 |
| 2.8 | `[x]` | **Raster vs. Liste:** Umschaltbare **Listen- oder Tabellenansicht** als Alternative zum Grid (gleiche Datenquelle/Filter; kein separater Workflow). Optional UIkit-Table-Komponenten. | 2.5 |
| 2.9 | `[x]` | **Polish & Redaktions-UX:** UIkit-**Transitions** und **Hover-Zustände** durchgängig; **keine Roh-IDs** für Redakteure sichtbar (überall Titel, Dateiname oder neutrale Bezeichner — technische IDs nur falls absolut nötig und nicht im Standard-UI). | 2.5–2.8 |

---

## Phase 3 — Bildbearbeitung vertiefen

**Ziel:** PW-Pipeline nutzen, keine zweite Bild-Engine parallel.

| # | Schritt | Beschreibung | Nach |
|---|---------|--------------|-----|
| 3.1 | `[x]` | **Rotation/Resize review:** Sicherstellen, dass Operationen auf dem Original/`Pageimage` laufen und Vorschau/Cache-Busting stimmt. | Phase 0 |
| 3.2 | `[x]` | **Zuschneiden (Crop):** Über PW-API (z. B. `size()` mit Cropping, oder passende Optionen) + einfache UI — Umfang pragmatisch halten. | 3.1 |
| 3.3 | `[x]` | **Externe Lib nur falls nötig:** Evaluation dokumentieren; wenn ja, eine Abhängigkeit mit Begründung. | bei Bedarf |
| 3.4 | `[x]` | **Bulk: Bilder nachträglich optimieren** (z. B. **WebP-Variante** neben dem Original, oder feste Ausgabe-Presets): Auswahl im Grid/Liste; serverseitig nur mit vorhandenen PW-/ImageSizer- bzw. GD/Imagick-Möglichkeiten; klare Regeln (wann neu berechnen, Speicherort, Fallback für ältere Browser). Optional abhängig von Phase 3.1. | 3.1, Phase 2.2 |

---

## Phase 4 — Architektur, Modularisierung & Dokumentation im Modul

**Ziel:** Wartbarkeit, Erweiterbarkeit — **das gesamte Bundle** (Process-Modul, API, Assets, Inputfield, später Fieldtype) **sinnvoll schneiden**, ohne Over-Engineering.

| # | Schritt | Beschreibung | Nach |
|---|---------|--------------|-----|
| 4.1 | `[x]` | **Modularisierung / Aufteilung:** Zuständigkeiten trennen (z. B. AJAX-Handler-Klasse, Render-Helfer, Services, Traits) — `*_module.php` als dünnere Fassade; nachvollziehbare Ordner-/Namenskonvention für das **gesamte** Modul; keine unnötige Fragmentierung. | Phase 0 |
| 4.2 | `[x]` | **PHPDoc** an öffentlichen API-Methoden; kurze Abschnittskommentare bei komplexen Blöcken. | laufend / 4.1 |
| 4.3 | `[x]` | **Optional `API.md`** im Modul (nur wenn gewünscht): Kurzreferenz für Entwickler; mit **Phase 7** abgleichen, sobald Fieldtype & Template-API stehen. | 4.2, Phase 7 |

---

## Phase 5 — Aufräumen Installation / Deinstallation

| # | Schritt | Beschreibung | Nach |
|---|---------|--------------|-----|
| 5.1 | `[x]` | **`uninstall`:** Strategie dokumentieren und optional implementieren (Daten löschen vs. nur Modul deaktivieren — oft Daten behalten). | Phase 0 |

---

## Phase 6 — Sicherheit & Härtung

**Ziel:** Das Modul verhält sich **bewusst sicher** — nicht nur zufällig; Anforderungen sind prüfbar und nachziehbar.

| # | Schritt | Beschreibung | Nach |
|---|---------|--------------|-----|
| 6.1 | `[x]` | **Zugriff & Schreibaktionen:** `medien-manager`-Permission konsequent; **CSRF** bei allen mutierenden Requests (inkl. AJAX); keine privilegierten Aktionen ohne Session/Auth. | Phase 0 |
| 6.2 | `[x]` | **Eingaben & Uploads:** Dateitypen/Größen serverseitig enforce’n; Pfade und Benennung sanitizen; keine Ausführung hochgeladener Inhalte; wo nötig **Superuser**-Gates für riskante Diagnose-Endpunkte. | 6.1 |
| 6.3 | `[x]` | **Ausgabe & Betrieb:** Keine sensiblen Daten in Logs/JSON für Redakteure; **Security-Review**-Checkliste (kurz dokumentiert, z. B. in `API.md` oder Modul-README-Abschnitt). | 6.2 |

---

## Phase 7 — Fieldtype & Template-Integration (Frontend)

**Ziel:** Redakteure wählen Medien aus dem Manager auf **Seiten** per Feld; Entwickler nutzen eine **klare Template-API** mit **Code-Snippets** — vergleichbar mit ProcessWires **Image** / **Images** (Felddokumentation, typische `$page->…`-Muster).

| # | Schritt | Beschreibung | Nach |
|---|---------|--------------|-----|
| 7.1 | `[x]` | **Fieldtype + Inputfield:** Feld speichert Referenz(en) auf Medien-Items (Page-IDs oder abgeleitetes Modell); Validierung, leere Werte, kompatibel mit bestehendem `InputfieldMedienManager` oder klar getrennte Evolution. | Phase 4.1 |
| 7.2 | `[x]` | **Template-API & Snippets:** Ausgabe im Frontend (URL, `Pageimage` wo möglich, Alt-Text aus Medien-Metadaten); **Codebeispiele** (ein Bild / mehrere Bilder) analog zu `Image`/`Images`-Dokumentation; Verweis in **API.md** oder zentraler Modul-Doku. | 7.1 |
| 7.3 | `[x]` | **Edge Cases:** Was passiert bei gelöschtem Medien-Item, fehlender Datei, Rechte im Frontend — definiertes Verhalten (NullPage, leere Ausgabe, Hinweis). | 7.2 |

---

## Phase 8 — ProcessWire-Core-Harmonisierung (Stabilitäts- & Hook-Fundament)

**Ziel:** Beseitigung subtiler Architektur- und Typ-Schwachstellen (identifiziert durch den Core-Skill 3.0.257) als verlässliches Fundament für Frontend-API und UI.

| # | Schritt | Beschreibung | Nach |
|---|---------|--------------|-----|
| 8.1 | `[x]` | **`FieldtypeMedienManager::getBlankValue()`:** Explizites Überschreiben mit Rückgabe eines leeren `PageArray` (`$pages->newPageArray()`) statt des geerbten `WireArray` — garantiert konsistente Methoden auf ungespeicherten/leeren Feldern. | Phase 7 |
| 8.2 | `[x]` | **Eigenschafts-Vererbung `getInputfield()`:** Übergabe von `maxItems` und `allowedTypes` vom `Field` an die `Inputfield`-Instanz (`$inputfield->set(...)`), damit Grenzwerte und Filter auch im Kontext von Ajax/Repeaters zuverlässig greifen. | Phase 7 |
| 8.3 | `[x]` | **`MediaManagerAPI extends Wire`:** Anbindung an ProcessWires Hook-Dispatcher (`Wire::__call()`); Kernmethoden hookbar deklarieren (`___createMediaItem()`, `___findMedia()`, `___replacePrimaryFile()`, `___deleteMedia()`). | Phase 4 |
| 8.4 | `[x]` | **Robuste Initialisierung:** Absicherung gegen Null-Referenzen bei `$this->wire->page` in `init()` für CLI- und API-Aufrufe. | Phase 0 |

---

## Phase 9 — Modernes Visual Styling & UI-Polish (Admin & Picker)

**Ziel:** Frische, zeitgemäße Optik für Redakteure bei voller UIkit-Kompatibilität, subtilen Animationen und klarem Feedback.

| # | Schritt | Beschreibung | Nach |
|---|---------|--------------|-----|
| 9.1 | `[x]` | **Modernes Kartendesign:** Weiche Schatten (`box-shadow: 0 4px 14px rgba(0,0,0,0.05)`), abgerundete Ecken (`border-radius: 10px`), sanfter Hover-Lift, Frosted-Glass-Aktions-Overlay (`backdrop-filter: blur(6px)`). | Phase 2 |
| 9.2 | `[x]` | **Toolbar & Bulk-Bar:** Visuelle Verbindung, moderner Segment-Umschalter Raster/Liste, akzentuierte Auswahlzustände und animierte Zähler-Badges. | Phase 2 |
| 9.3 | `[x]` | **Upload-Modal & Dropzone:** Moderne Drag&Drop-Optik mit Puls-Feedback und Datei-Preview-Chips. | Phase 1 |
| 9.4 | `[x]` | **Picker-Modal-Polish:** Modernisiertes Such- und Filter-Layout im Seiten-Editor mit schnellen visuellen Zuständen. | Phase 7 |
| 9.5 | `[x]` | **Feedback & Toasts:** Zuverlässige UIkit-Erfolgsbenachrichtigungen beim Zuweisen von Kategorien (Bulk & Einzeln) sowie bei Kategorie-Verwaltung. | Phase 2 |


---

## Phase 10 — Frontend-Mini-API & Responsive WebP/Picture Pipeline

**Ziel:** 1-Zeilen-Template-Ausgabe mit automatisiertem WebP und responsiven Größen (`<picture>` / `srcset`) auf Basis des Hook-Fundaments.

| # | Schritt | Beschreibung | Nach |
|---|---------|--------------|-----|
| 10.1 | `[x]` | **Hook-basierte Mini-API:** `$media->render([options])` und `$page->mein_feld->render()` direkt über ProcessWire-Hooks (`addHookMethod`) verfügbar machen — kein manuelles `new MediaManagerAPI()` im Template nötig. | Phase 8 |
| 10.2 | `[x]` | **Responsive `<picture>`-Pipeline:** Automatische Generierung von `<picture>` mit `srcset` und frei definierbaren Breakpoints (z. B. 400w, 800w, 1200w). | 10.1 |
| 10.3 | `[x]` | **On-the-Fly WebP-Skalierung:** Für jeden skalierten Breakpoint wird automatisch die passende `.webp`-Variante erzeugt und im `<picture>` als `<source type="image/webp">` ausgespielt. | 10.2, Phase 3 |
| 10.4 | `[x]` | **Accessibility & Caption:** Automatisches Einbinden von `alt` (`mm_alt` / Titel) und `<figcaption>` (`mm_caption`), konfigurierbar per Option. | 10.1 |
| 10.5 | `[x]` | **Schlanke Helper:** Direkte Eigenschaften und Kurzmethoden: `$media->url(w, h)`, `$media->alt`, `$media->caption`. | 10.1 |

---

## Phase 11 — Redaktionskomfort & Asset-Intelligence

**Ziel:** Funktionen auf Augenhöhe mit modernen Headless- und Asset-Systemen.

| # | Schritt | Beschreibung | Nach |
|---|---------|--------------|-----|
| 11.1 | `[x]` | **Verwendungsnachweis („Used on pages“):** Rückwärtige Referenzanzeige im Detail/Grid („Verwendet auf: Seite A, Seite B“); Warnung vor dem Löschen genutzter Medien. | Phase 8 |
| 11.2 | `[x]` | **„Unbenutzte Medien“-Filter:** Schnelles Auffinden und Bereinigen verwaister Assets zur Speicherplatzoptimierung. | 11.1 |
| 11.3 | `[x]` | **Focal Point (Intelligenter Crop):** Klick-Fadenkreuz auf das Hauptmotiv im Bildeditor; ProcessWire-ImageSizer croppt responsiv auf diesen Fokuspunkt. | Phase 3 |
| 11.4 | `[x]` | **Sichere SVG-Unterstützung:** Upload und Ausgabe von `.svg` mit integriertem XML/Script-Sanitizer gegen Stored XSS. | Phase 6 |

---

## Changelog dieses Plans

| Datum | Änderung |
|-------|----------|
| 2026-04-13 | Erstversion |
| 2026-04-13 | Phase 0 im Code umgesetzt — **Checkboxen `[ ]` erst nach deiner Test-Bestätigung** auf `[x]` setzen. |
| 2026-04-13 | **Phase 1** im Code umgesetzt (Mehrfach-Upload, Slot-Konstanten, natives `loading="lazy"`). Checkboxen `[ ]` → `[x]` nach deiner Bestätigung. |
| 2026-04-13 | **Phase 1** ausdrücklich bestätigt; **Phase 0** mitabgenommen (bereits umgesetzt, gemeinsam mit Phase 1 getestet). |
| 2026-04-13 | **Phase 2** im Code umgesetzt (mm_alt/mm_caption, Bulk, URL kopieren, Duplizieren, Datei ersetzen). Modulversion **1.4**; nach Update Modul aktualisieren. |
| 2026-04-13 | **Phase 2** um UX-Arbeitspakete erweitert (2.5–2.9): UIkit-Oberfläche Grid/Bulk/Edit, Listenansicht, Polish; keine neue Phasennummerierung. |
| 2026-04-13 | **Phase 2.5–2.9** im Code umgesetzt (UIkit-Card Toolbar+Bulk, Grid-Overlay, Liste, Meta-Infos, Edit-Zweispalter). Modulversion **1.5**. |
| 2026-04-13 | Plan erweitert: **Phase 4** (Modularisierung gesamtes Modul), **Phase 6** (Sicherheit), **3.4** (Bulk-Bildoptimierung/WebP), **Phase 7** (Fieldtype + Template-Snippets wie Image/Images). |
| 2026-04-13 | **Phase 3** im Code umgesetzt: Master-Resize/Crop (`ImageSizer`), Grid-Thumbs nach Bearbeitung, Bulk-WebP (`Pageimage::webp()`), Kurzevaluation in `PHASE3_NOTES.md`. Modulversion **1.6**. |
| 2026-04-13 | **Phase 4** im Code umgesetzt: `lib/MediaManagerAjaxTrait.php`, `lib/MediaManagerRenderTrait.php`, PHPDoc an `MediaManagerAPI`, `API.md`. Modulversion **1.7**. |
| 2026-04-13 | **Phase 5** umgesetzt: `README.md` (Bedienung + Erweiterungsabschnitte), Deinstallationsstrategie, `MediaManagerAPI::uninstall()` leert Modul-Config. Modulversion **1.8**. |
| 2026-04-13 | **Phase 6** umgesetzt: Schreib-AJAX nur POST+CSRF, Upload-Größe (ini + Modul-MB), Bulk-ID-Limit 500, Test-JSON ohne GET-Werte, `SECURITY.md`. Modulversion **1.9**. |
| 2026-04-13 | **Phase 7** umgesetzt: `MedienManagerField`, Fieldtype-Vorlagen (Image/Images-ähnlich), Snippets in Feldkonfiguration, `getAccessibleLabel`/`getCaption`/`hasRenderableImage`, Picker-Filter `allowed_types`, `API.md` erweitert. Modulversion **2.0**. |
| 2026-09-13 | **Phasen 8 bis 11** ergänzt: Phase 8 (ProcessWire-Core-Harmonisierung & Hook-Fundament aus Skill-Review), Phase 9 (Visual Styling & UI-Polish), Phase 10 (Frontend-Mini-API & Responsive WebP/`<picture>`), Phase 11 (Asset-Intelligence: Verwendungsnachweis, Focal Point, SVG-Sanitizer). |
| 2026-09-13 | **Phase 8** umgesetzt und bestätigt (Version **2.1.0**). |
| 2026-09-13 | **Phase 9** im Code umgesetzt: Modernes Visual Styling (weiche Schatten, Hover-Lift, Frosted-Glass-Overlays, Pill-Badges für Medientypen, animierte Segment-Controls, Dropzone-Polish, Inputfield-Chips mit Checkmark-Animation) und Toast-Erfolgsmeldungen beim Kategorie-Setzen. |
| 2026-09-13 | **Phase 9** getestet und bestätigt. |
| 2026-09-13 | **Phase 10** umgesetzt: Frontend-Mini-API (`$media->render()`, `$page->feld->render()`), responsive `<picture>`-Pipeline mit WebP-Generierung, Zero-CLS, Accessibility/Caption und Helper-Properties. Modulversion auf **2.2.0** erhöht. |
| 2026-09-13 | **Phase 11** umgesetzt: Asset-Intelligence & Redaktionskomfort (Verwendungsnachweis mit Rückwärts-Referenzsuche und Löschschutz bei referenzierten Medien, Filter für unbenutzte Medien, interaktiver Focal Point Editor mit nativer ProcessWire `Pageimage::focus()`-Speicherung und CSS-Ausrichtung, sicherer SVG-Upload mit XML/Script-Sanitizer gegen XSS/XXE). Modulversion auf **2.3.0** erhöht. |

---

*Ende des Phasenplans.*
