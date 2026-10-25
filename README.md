# bsProcessMedienManager — Medien Manager für ProcessWire

![PHP](https://img.shields.io/badge/PHP-%3E%3D8.0-777BB4?style=flat-square)
![ProcessWire](https://img.shields.io/badge/ProcessWire-3.x-2480e6?style=flat-square)

Zentrales **ProcessWire**-Modul für Bilder, Videos und PDFs: Upload, Metadaten, Kategorien, Suche und Admin-Oberfläche unter **Einstellungen → Medien Manager**.

| Dokument | Inhalt |
|----------|--------|
| Diese **README** | Überblick, Bedienung, FAQ, Deinstallation — für Menschen & GitHub |
| [`API.md`](API.md) | Technische Referenz (Klassen, AJAX, URLs) |
| [`SECURITY.md`](SECURITY.md) | Sicherheits-Checkliste (Rechte, CSRF, Uploads) |
| [`PHASENPLAN.md`](PHASENPLAN.md) | Roadmap und erledigte Phasen |

---

## Inhaltsverzeichnis

1. [Repository-Struktur](#repository-struktur)
2. [Voraussetzungen](#voraussetzungen)
3. [Installation](#installation)
4. [Updates](#updates)
5. [FAQ](#faq)
6. [Bedienung für Redakteure](#bedienung-für-redakteure)
7. [Neue Funktionen und Versionshinweise](#neue-funktionen-und-versionshinweise)
8. [Erweiterte Hinweise](#erweiterte-hinweise)
9. [Deinstallation und Daten](#deinstallation-und-daten)
10. [Entwicklung](#entwicklung)

Hinweis: Detaillierte Sicherheitsübersicht → [`SECURITY.md`](SECURITY.md).

---

## Repository-Struktur

```
bsProcessMedienManager/
├── bsProcessMedienManager.module.php   # Process-Modul (Routen, Konfiguration)
├── MediaManagerAPI.php                 # Domänen-Fassade (hookbar)
├── lib/
│   ├── MediaManagerAjaxTrait.php       # JSON-Endpunkte
│   ├── MediaManagerRenderTrait.php     # Admin-Markup
│   └── services/                       # Domänen-Services (Items, Files, Image, …)
├── InputfieldMedienManager.module.php
├── FieldtypeMedienManager.module.php
├── js/ · css/
├── API.md
├── README.md
├── SECURITY.md
└── PHASENPLAN.md
```

---

## Voraussetzungen

- ProcessWire **3.x**
- **PHP ≥ 8.0**
- Nach Installation den Benutzerrollen die Berechtigung **„Medien Manager verwenden“** (`medien-manager`) zuweisen

---

## Installation

1. Repository bzw. Modulordner nach `site/modules/bsProcessMedienManager/` legen  
2. Im Backend **Module** öffnen → **Medien Manager** installieren  

---

## Updates

1. Dateien im Modulordner ersetzen (oder `git pull` im Projekt)  
2. Im Backend **Module** → **Aktualisieren** / zur Modulzeile navigieren und **aktualisieren** ausführen  

So werden `___upgrade`-Schritte und Schema-Anpassungen ausgeführt.

---

## FAQ

### Muss ich nach einem Update deinstallieren und neu installieren?

**Nein — das ist für normale Updates nicht nötig.**  
Nach dem Kopieren der neuen Version reicht **Modul aktualisieren** im ProcessWire-Backend. So bleiben Konfiguration, Medien-Daten und Berechtigungen erhalten und Upgrade-Hooks laufen wie vorgesehen.

### Wann sind Deinstallation und Neuinstallation sinnvoll?

Nur wenn du **explizit** testen willst:

- ob die **Deinstallation** (Menü weg, Config leer) so wirkt wie dokumentiert, oder  
- eine **Neuinstallation auf einer leeren / Kopie-Installation** (Staging).

> [!WARNING]
> **Deinstallation auf einer produktiven Seite** nur mit Backup und Bewusstsein: Die Admin-Oberfläche verschwindet, die Modul-Konfiguration wird geleert; Medien-**Seiten** und **Dateien** werden standardmäßig **nicht** automatisch mitgelöscht (siehe unten). Fieldtype-Felder, die noch auf Medien verweisen, vorher prüfen.

### Reicht „Refresh“ der Module-Liste?

Das allein ersetzt keinen **Update-Klick**. Nach Dateiänderungen immer **Module aktualisieren** für diese Modulversion ausführen.

---

## Bedienung für Redakteure

### Übersicht

- Menü: **Einstellungen → Medien Manager**
- Umschalten zwischen **Raster** und **Liste**
- Filter: Typ (Bild/Video/PDF), Kategorie, Verwendung (**Alle / Nur unbenutzte / Nur verwendete**), Freitextsuche
- Pro Medium: Typ-Badge, Verwendungszähler (Link-Badge), Dateiname, Abmessungen/Größe

### Medien hochladen

1. Button **Hochladen** → Dialog (Drag & Drop oder Dateiauswahl)
2. Mehrere Dateien möglich; optional Titel, Kategorie, Tags
3. Bei mehreren Dateien: Titel oft aus dem Dateinamen

**Erlaubte Formate:** JPG, JPEG, PNG, GIF, WebP, SVG, MP4, MOV, PDF  
SVGs werden serverseitig bereinigt (kein ausführbares Scripting).

### Bearbeiten

- Klick auf Bearbeiten: links Vorschau (sticky), rechts Metadaten
- Felder: Titel, Beschreibung, Tags, **Alternativtext**, **Beschriftung**, Typ, Kategorie
- Block **Verwendung im System**: listet Seiten, die dieses Medium referenzieren
- **Datei ersetzen** nur mit **gleicher Dateiendung** (Verweise bleiben gültig)
- **URL kopieren** (Grid/Aktionen) → öffentliche Datei-URL in die Zwischenablage

### Bildbearbeitung

- **Drehen** und **Größe/Zuschnitt** wirken auf die Originaldatei (Master)
- **Fokuspunkt (Focal Point):** Fadenkreuz auf dem Motiv setzen — Zuschnitte im Frontend orientieren sich daran
- SVG: kein Pixel-Resize/Crop nötig (Vektor)

### Mehrfachauswahl (Bulk)

1. Checkboxen im Raster/Liste setzen (Zähler z. B. „3 von 12 ausgewählt“)
2. Aktionen: Kategorie setzen, **WebP erzeugen**, Löschen
3. Beim Löschen: Warnung, wenn Medien noch auf Seiten verwendet werden — erst nach Bestätigung (`force`)

### Medien auf einer Seite auswählen (Feld)

1. Feldtyp **Medien (Manager)** im Template
2. Im Seiten-Editor: Picker-Modal (Suche/Filter), Auswahl je nach Feld-Limit (ein/mehrere)
3. Erlaubte Typen können in der Feldkonfiguration eingeschränkt sein

### Bilder in TinyMCE einfügen

Wenn auf derselben Seite ein **Medien (Manager)**-Feld Bilder enthält und ein Textfeld TinyMCE mit dem ProcessWire-Bild-Button (**pwimage**) nutzt:

1. Medien im Manager-Feld der Seite zuweisen und Seite speichern (falls neu)
2. Im Editor auf **Bild einfügen** (pwimage)
3. Die Primärbilder der zugewiesenen Medien-Items erscheinen in der Auswahl — analog zu einem klassischen Bilder-Feld

Hinweise: Nur Rasterbilder (inkl. SVG); Videos/PDFs nicht. Drag&Drop-Upload aus TinyMCE zielt weiter auf echte Image-Felder. Größe/Zuschnitt im Dialog wirkt auf die Datei in der **Medienbibliothek**.

### Suche

Berücksichtigt u. a. Titel, Tags, Beschreibung, Alternativtext und Beschriftung.

### Kategorien

Eigene Verwaltungsseite im Medien-Manager-Menü: anlegen und löschen; Zuweisung beim Upload, im Edit und per Bulk.

---

## Neue Funktionen und Versionshinweise

*Bei Releases hier ergänzen: Was ist neu, Breaking Changes, Migration.*

| Version | Kurz |
|---------|------|
| 2.5.0 | TinyMCE: `FieldtypeHasPageimages` — Bilder aus Medien-Manager-Feld der Seite einfügen |
| 2.4.0 | Architektur: Domänenlogik in Services unter `lib/services/`; `MediaManagerAPI` als stabile Fassade |
| 2.3.1 | Konsolidierung: Usage-Filter inkl. Page-Referenzen; Inputfield-Version sync; Hook-API-Cache; Redakteurs-README + Smoke-Checkliste |
| 2.3.0 | Phase 11: Verwendungsnachweis, unbenutzte Medien, Focal Point, SVG-Sanitizer |
| 2.2.0 | Phase 10: Frontend-Mini-API, responsive `<picture>`/WebP |
| 2.1.0 | Phase 8: Core-Harmonisierung (PageArray blank, Hooks) |
| 2.0 | Phase 7: Fieldtype + Template-Snippets |
| 1.9 | Phase 6: CSRF+POST, Upload-Limit, Bulk-ID-Limit, `SECURITY.md` |

---

## Erweiterte Hinweise

- **Frontend-Ausgabe:** siehe [`API.md`](API.md) (`$page->mein_feld->render()`, Helper-Properties).
- **Performance:** Thumbnail-Slots sind fest; WebP/Picture erzeugen Variationen on demand — bei sehr großen Bibliotheken Pagination und Filter nutzen.
- **Backup:** vor Massenlöschung oder Deinstallation Seiten- und Dateibackup empfehlen.
- **Nach Modul-Update:** im Backend **Module → Medien Manager → aktualisieren** (nicht nur Refresh).

---

## Deinstallation und Daten

**Standardverhalten:**

| Was | Verhalten |
|-----|-----------|
| Admin-Menü „Medien Manager“ | wird entfernt (Process-Page) |
| Modul-Konfiguration (`rootPageID`, Raster-Limit, …) | wird **geleert** |
| Medien-Seiten (`medienmanager-item`), Kategorien, Dateien unter `site/assets/files/` | werden **nicht** automatisch gelöscht |
| Felder `mm_*` und Templates | werden **nicht** automatisch entfernt |

Vollständiges Entfernen von Inhalten nur geplant mit Backup und ggf. manuellem Aufräumen der Seiten/Felder.

---

## Entwicklung

| Datei | Thema |
|-------|--------|
| [`API.md`](API.md) | Klassen, Traits, AJAX, URLs |
| [`SECURITY.md`](SECURITY.md) | Sicherheits-Checkliste |
| [`PHASENPLAN.md`](PHASENPLAN.md) | Roadmap |
| [`PHASE3_NOTES.md`](PHASE3_NOTES.md) | Bildpipeline / WebP (Kurznotiz) |
