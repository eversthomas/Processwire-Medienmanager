<?php namespace ProcessWire;

/**
 * Zentrale Domänen-API für Medien-Items (Pages unter dem Manager-Root).
 *
 * Kapselt Suche, Erstellung, Dateien, Kategorien und Bildoperationen (Variationen, Master-Resize,
 * WebP). Wird vom Process-Modul, Upload-Flows und optional von anderen Modulen genutzt.
 */
class MediaManagerAPI extends Wire {

	const ROOT_TEMPLATE      = 'medienmanager-root';
	const ITEM_TEMPLATE      = 'medienmanager-item';
	const KATEGORIE_TEMPLATE = 'medienmanager-kategorie';
	const ROOT_NAME          = 'medienmanager';

	/**
	 * Standard-Vorschau-Variation beim Upload (entspricht Slot „grid“).
	 * Physische Datei per Pageimage::size() neben dem Original.
	 *
	 * @see https://processwire.com/api/ref/pageimage/size/
	 */
	public const THUMB_WIDTH  = 300;
	public const THUMB_HEIGHT = 300;

	/** Vorschau-Slot: Medien-Grid (Kacheln) */
	public const SLOT_GRID_W = 300;
	public const SLOT_GRID_H = 300;
	/** Modal-Picker */
	public const SLOT_PICKER_W = 160;
	public const SLOT_PICKER_H = 120;
	/** Bearbeiten-Formular Vorschau */
	public const SLOT_EDIT_W = 300;
	public const SLOT_EDIT_H = 225;
	/** Inputfield-Chips */
	public const SLOT_CHIP_W = 120;
	public const SLOT_CHIP_H = 90;

	public function __construct(?ProcessWire $wire = null) {
		parent::__construct();
		if($wire) {
			$this->wire($wire);
		}
	}

	/**
	 * Verwaltungs-Root-Page (versteckt unter Admin), Fallback aus Modul-Konfiguration.
	 */
	protected function _getRootPage(): Page {
		$adminId = (int) $this->wire->config->adminRootPageID;
		// Root wird bei install als Child von admin angelegt (Template i. d. R. „admin“, nicht medienmanager-root).
		return $this->wire->pages->get("name=" . self::ROOT_NAME . ", parent=$adminId, include=all");
	}

	/** @return int Page-ID des Medien-Manager-Roots (Konfiguration oder ermittelt) */
	public function getRootPageId(): int {
		$config = $this->wire->modules->getModuleConfigData('bsProcessMedienManager');
		$id = isset($config['rootPageID']) ? (int) $config['rootPageID'] : 0;
		if($id <= 0) $id = $this->_getRootPage()->id;
		return (int) $id;
	}

	/**
	 * Medien-Items mit optionalen Filtern (Typ, Kategorie, Volltextsuche).
	 *
	 * @param array{typ?: string, typs?: list<string>, kategorie_id?: int, q?: string} $filters
	 */
	public function ___findMedia(array $filters = [], int $start = 0, int $limit = 24): PageArray {
		$sanitizer = $this->wire->sanitizer;
		$selector = "template=" . self::ITEM_TEMPLATE . ", include=hidden, sort=-created";

		if(!empty($filters['typ'])) {
			$typ = $sanitizer->name($filters['typ']);
			$selector .= ", mm_typ.title=$typ";
		} elseif(!empty($filters['typs']) && is_array($filters['typs'])) {
			$parts = [];
			foreach($filters['typs'] as $t) {
				$t = $sanitizer->name((string) $t);
				if($t !== '') {
					$parts[] = "mm_typ.title=$t";
				}
			}
			if(count($parts) === 1) {
				$selector .= ', ' . $parts[0];
			} elseif(count($parts) > 1) {
				$selector .= ', (' . implode('|', $parts) . ')';
			}
		}
		if(!empty($filters['kategorie_id'])) {
			$katId = (int) $filters['kategorie_id'];
			if($katId > 0) $selector .= ", mm_kategorie=$katId";
		}
		if(!empty($filters['q'])) {
			$q = $sanitizer->selectorValue($filters['q']);
			// OR über Felder: mm_titel|mm_tags%=wert — NICHT mm_titel%=wert|mm_tags%=wert
			// (sonst endet der Wert beim ersten | und mm_tags wird ignoriert).
			$suchFelder = ['mm_titel'];
			if($this->wire->fields->get('mm_tags')) {
				$suchFelder[] = 'mm_tags';
			}
			if($this->wire->fields->get('mm_beschreibung')) {
				$suchFelder[] = 'mm_beschreibung';
			}
			if($this->wire->fields->get('mm_alt')) {
				$suchFelder[] = 'mm_alt';
			}
			if($this->wire->fields->get('mm_caption')) {
				$suchFelder[] = 'mm_caption';
			}
			$selector .= ', ' . implode('|', $suchFelder) . '%=' . $q;
		}

		if(!empty($filters['usage'])) {
			$usage = strtolower((string) $filters['usage']);
			if($usage === 'unused') {
				$usedIds = $this->getAllUsedMediaIds();
				if(!empty($usedIds)) {
					$selector .= ', id!=' . implode('|', $usedIds);
				}
			} elseif($usage === 'used') {
				$usedIds = $this->getAllUsedMediaIds();
				if(!empty($usedIds)) {
					$selector .= ', id=' . implode('|', $usedIds);
				} else {
					$selector .= ', id=0';
				}
			}
		}

		$selector .= ", start=$start, limit=$limit";
		return $this->wire->pages->find($selector);
	}

	/** Einzelnes Medien-Item oder leere Page bei ungültiger ID. */
	public function ___getMediaItem(int $id): Page {
		return $this->wire->pages->get("id=" . (int)$id . ", template=" . self::ITEM_TEMPLATE . ", include=all");
	}

	/** Kategorie-Pages unter dem Root (oder unter $parentId). */
	public function ___getKategorien(int $parentId = 0): PageArray {
		if($parentId <= 0) $parentId = $this->getRootPageId();
		return $this->wire->pages->find("parent=$parentId, template=" . self::KATEGORIE_TEMPLATE . ", include=hidden, sort=title");
	}

	/**
	 * Neues Medien-Item inkl. optionaler Datei (Bild → mm_bild, sonst mm_datei).
	 *
	 * @param array{titel?: string, typ?: string, beschreibung?: string, tags?: string, kategorie_id?: int} $data
	 */
	public function ___createMediaItem(array $data, string $uploadedFile = '', string $originalName = ''): Page {
		$sanitizer = $this->wire->sanitizer;
		$rootId = $this->getRootPageId();
		if($rootId <= 0) return $this->wire->pages->newNullPage();

		$p = $this->wire->pages->newPage(['template' => self::ITEM_TEMPLATE, 'parent' => $rootId]);
		$p->title = $sanitizer->text($data['titel'] ?? 'Unbenannt');
		$p->mm_titel = $p->title;

		$typMap = ['bild' => 1, 'video' => 2, 'pdf' => 3];
		$p->mm_typ = $typMap[$data['typ'] ?? 'bild'] ?? 1;

		if($p->hasField('mm_beschreibung') && array_key_exists('beschreibung', $data)) {
			$p->mm_beschreibung = $this->wire->sanitizer->textarea((string) $data['beschreibung']);
		}
		if($p->hasField('mm_tags') && array_key_exists('tags', $data)) {
			$p->mm_tags = $sanitizer->text((string) $data['tags']);
		}

		if(!empty($data['kategorie_id'])) {
			$kat = $this->wire->pages->get((int) $data['kategorie_id']);
			if($kat->id) $p->mm_kategorie = $kat;
		}

		$p->addStatus(Page::statusHidden);
		$p->save();

		if($uploadedFile && file_exists($uploadedFile)) {
			$p->of(false);
			$safeBasename = '';
			if($originalName !== '') {
				$safeBasename = $sanitizer->filename($originalName, true);
			}
			$ext = strtolower(pathinfo($safeBasename !== '' ? $safeBasename : $uploadedFile, PATHINFO_EXTENSION));
			$isImage = in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'], true);

			if($ext === 'svg' && !$this->sanitizeSvgFile($uploadedFile)) {
				$this->wire->log->error("MM Upload: SVG-Sanitization fehlgeschlagen für $originalName");
				$this->wire->pages->delete($p, true);
				return $this->wire->pages->newNullPage();
			}

			if($isImage && $p->hasField('mm_bild')) {
				$p->mm_bild->add($uploadedFile);
				if($safeBasename !== '') $p->mm_bild->last()->rename($safeBasename);
				$p->save();
				if($ext !== 'svg') {
					$this->createVariants($p);
					$this->ensureWebpAfterVariants($p);
				}
			} else {
				$p->mm_datei->add($uploadedFile);
				if($safeBasename !== '') $p->mm_datei->last()->rename($safeBasename);
				$p->save();
			}
		}
		return $p;
	}

	/**
	 * Nach `createVariants()`: WebP-Nebenversion für das primäre Bild (wie Bulk „WebP erzeugen“).
	 */
	protected function ensureWebpAfterVariants(Page $p): void {
		$pid = (int) $p->id;
		if($pid <= 0) return;
		$fresh = $this->wire->pages->getFresh($pid);
		if(!$fresh->id) return;
		$fresh->of(false);
		$img = $this->getPrimaryPageimage($fresh);
		if($img instanceof Pageimage) {
			$this->ensureWebpForPageimage($img);
		}
	}

	/**
	 * Legt die feste Admin-/Grid-Vorschau als Pageimage-Variation auf der Festplatte an.
	 *
	 * ProcessWire erzeugt keine „Thumbnails“ als separaten Mechanismus: Variationen entstehen
	 * durch $image->size($w, $h, $options) im files-Verzeichnis der Page (neben dem Original).
	 * Optional am Feld mm_bild: maxWidth/maxHeight (ImageField) — skaliert dann die Hauptdatei
	 * beim Upload, nicht als zweite Datei. Benannte Presets: $config->imageSizes (seit 3.0.151).
	 *
	 * Wichtig: Nach mm_bild->save() liefert pages->get() oft dieselbe gecachte Page ohne
	 * frisch geladene Dateien — dann ist mm_bild leer und size() wird nie aufgerufen.
	 * pages->getFresh() lädt die Page bewusst neu aus der DB (siehe PW-API pages->getFresh).
	 */
	public function ___createVariants(Page $p): void {
		$pid = (int) $p->id;
		if($pid <= 0) return;

		$page = $this->wire->pages->getFresh($pid);
		if(!$page->id || !$page->hasField('mm_bild') || !count($page->mm_bild)) {
			$this->wire->log->save('medienmanager', "createVariants: keine mm_bild nach Upload (Page $pid)");
			return;
		}

		try {
			$page->of(false);
			$img = $page->mm_bild->first();
			if(!$img instanceof Pageimage) {
				$this->wire->log->save('medienmanager', 'createVariants: kein Pageimage, Klasse=' . ($img ? get_class($img) : 'null'));
				return;
			}
			if(strtolower((string) $img->ext) === 'svg') return;
			if(!is_file($img->filename())) {
				$this->wire->log->error('MM Variants: Original fehlt: ' . $img->filename());
				return;
			}

			$thumb = $img->size(self::SLOT_GRID_W, self::SLOT_GRID_H, $this->thumbnailSizeOptions());
			if($img->error) {
				$this->wire->log->error('MM Variants: ' . $img->error);
				return;
			}
			if(!$thumb->filename || !is_file($thumb->filename())) {
				$this->wire->log->error('MM Variants: Variationsdatei fehlt (Page ' . $pid . ')');
			}
		} catch(\Throwable $e) {
			$this->wire->log->error('MM Variants: ' . $e->getMessage());
		}
	}

	/**
	 * Optionen für Vorschau-Größen (Grid, Picker, Upload-Nachgenerierung).
	 * cropping: false = gesamtes Bild in die Box skaliert (ggf. Letterboxing), kein harter Zuschnitt.
	 */
	protected function thumbnailSizeOptions(): array {
		return ['cropping' => false];
	}

	/**
	 * Dreht die Original-Datei eines Pageimage per ImageSizer (In-Place), löscht veraltete Variationen.
	 *
	 * Hinweis: ProcessWire hat kein Pageimage::rotate(); Rotation erfolgt über ImageSizerEngine auf der Quelldatei.
	 *
	 * @return bool True bei Erfolg
	 */
	public function ___rotateMasterPageimage(Pageimage $bild, int $degrees): bool {
		if(!in_array(abs($degrees), [90, 180, 270], true)) return false;
		$filename = $bild->filename();
		if(!is_file($filename) || !is_readable($filename)) return false;

		$sizer = new ImageSizer($filename, []);
		$this->wire->wire($sizer);
		if(!$sizer->rotate($degrees)) {
			return false;
		}
		$bild->removeVariations();
		$bild->getImageInfo(true);
		return true;
	}

	/**
	 * Skaliert oder beschneidet die **Originaldatei** (Master) per ImageSizer — keine nur neue Variation wie `Pageimage::size()`.
	 *
	 * @param bool $cropping True: Zielbox wird gefüllt (ggf. beschnitten); False: proportionales Einpassen in die Box.
	 */
	public function ___resizeMasterPageimage(Pageimage $bild, int $width, int $height, bool $cropping = false): bool {
		$width  = max(1, $width);
		$height = max(1, $height);
		if(strtolower((string) $bild->ext) === 'svg') return false;
		$filename = $bild->filename();
		if(!is_file($filename) || !is_readable($filename)) return false;

		$options = [
			'cropping'  => $cropping,
			'upscaling' => false,
		];
		$sizer = new ImageSizer($filename, $options);
		$this->wire->wire($sizer);
		if(!$sizer->resize($width, $height)) {
			return false;
		}
		$bild->removeVariations();
		$bild->getImageInfo(true);
		return true;
	}

	/**
	 * Erzeugt eine .webp-Nebenversion falls die Engine es unterstützt und die Datei noch fehlt.
	 *
	 * @return bool True wenn WebP existiert oder neu erzeugt wurde
	 */
	public function ___ensureWebpForPageimage(Pageimage $img): bool {
		if(strtolower((string) $img->ext) === 'svg') return false;
		$webp = $img->webp();
		if($webp->exists()) return true;
		return (bool) $webp->create();
	}

	/** Vorschau-URL (Pageimage-Variation) mit festen Maßen. */
	public function getThumbnailUrl(Page $item, int $width = self::SLOT_GRID_W, int $height = self::SLOT_GRID_H): string {
		$img = $this->getPrimaryPageimage($item);
		if(!$img instanceof Pageimage) return '';
		if(strtolower((string) $img->ext) === 'svg') return $img->url;
		return $img->size($width, $height, $this->thumbnailSizeOptions())->url;
	}

	/**
	 * Thumbnail-URL für einen definierten Anzeige-Slot (einheitliche Maße im gesamten Modul).
	 *
	 * @param string $slot grid | picker | edit | chip
	 */
	public function getThumbnailUrlForSlot(Page $item, string $slot): string {
		$slot = strtolower($slot);
		$w    = self::SLOT_GRID_W;
		$h    = self::SLOT_GRID_H;
		if($slot === 'picker') {
			$w = self::SLOT_PICKER_W;
			$h = self::SLOT_PICKER_H;
		} elseif($slot === 'edit') {
			$w = self::SLOT_EDIT_W;
			$h = self::SLOT_EDIT_H;
		} elseif($slot === 'chip') {
			$w = self::SLOT_CHIP_W;
			$h = self::SLOT_CHIP_H;
		}
		return $this->getThumbnailUrl($item, $w, $h);
	}

	/** Normalisierter Typ: `bild` | `video` | `pdf`. */
	public function getTypString(Page $item): string {
		if (!$item->id || !$item->mm_typ) return 'bild';
		return strtolower((string) $item->mm_typ->title);
	}

	/**
	 * Text für `alt` und Screenreader: zuerst mm_alt, sonst sinnvoller Titel (SEO/A11y).
	 */
	public function getAccessibleLabel(Page $item): string {
		if(!$item->id) return '';
		$san = $this->wire->sanitizer;
		if($item->hasField('mm_alt') && trim((string) $item->mm_alt) !== '') {
			return $san->text((string) $item->mm_alt);
		}
		$t = trim((string) ($item->mm_titel ?: $item->title));

		return $t !== '' ? $san->text($t) : '';
	}

	/**
	 * Bildunterschrift (mm_caption) — kann leer sein.
	 */
	public function getCaption(Page $item): string {
		if(!$item->id || !$item->hasField('mm_caption')) return '';

		return $this->wire->sanitizer->text((string) $item->mm_caption);
	}

	/**
	 * True, wenn für Frontend-Ausgabe ein echtes Rasterbild (Pageimage) vorliegt.
	 */
	public function hasRenderableImage(Page $item): bool {
		return $this->getPrimaryPageimage($item) instanceof Pageimage;
	}

	/** Font-Awesome-Markup für Typ-Icon (Admin). */
	public function getMediaTypeIconHtml(string $typ, string $spanClass = 'mm-grid-icon'): string {
		$map  = ['video' => 'fa-film', 'pdf' => 'fa-file-pdf-o', 'bild' => 'fa-file-image-o'];
		$icon = $map[$typ] ?? 'fa-file-o';
		return "<span class='{$spanClass}'><i class='fa {$icon}'></i></span>";
	}

	/**
	 * Metadaten eines Medien-Items speichern (Titel, Typ, Kategorie, Tags, Alt, Caption …).
	 *
	 * @param array<string, mixed> $data
	 */
	public function ___saveMediaItem(int $id, array $data): Page {
		$p = $this->getMediaItem($id);
		if(!$p->id) return $p;
		$p->of(false);
		$sanitizer = $this->wire->sanitizer;
		$p->mm_titel = $sanitizer->text($data['titel'] ?? '');
		if(isset($data['beschreibung']) && $p->hasField('mm_beschreibung')) {
			$p->mm_beschreibung = $sanitizer->textarea((string) $data['beschreibung']);
		}
		if(isset($data['tags']) && $p->hasField('mm_tags')) {
			$p->mm_tags = $sanitizer->text((string) $data['tags']);
		}
		if(isset($data['typ']) && $data['typ'] !== '') {
			$typMap = ['bild' => 1, 'video' => 2, 'pdf' => 3];
			$t      = $sanitizer->name((string) $data['typ']);
			if(isset($typMap[$t])) $p->mm_typ = $typMap[$t];
		}
		if(array_key_exists('kategorie_id', $data)) {
			$p->mm_kategorie = (int) $data['kategorie_id'] ?: null;
		}
		if(isset($data['alt']) && $p->hasField('mm_alt')) {
			$p->mm_alt = $sanitizer->text((string) $data['alt']);
		}
		if(isset($data['caption']) && $p->hasField('mm_caption')) {
			$p->mm_caption = $sanitizer->text((string) $data['caption']);
		}
		$p->save();
		return $p;
	}

	/**
	 * Öffentliche URL der primären Datei (für Einbindung im Frontend).
	 */
	public function getPublicFileUrl(Page $item): string {
		$img = $this->getPrimaryPageimage($item);
		if($img instanceof Pageimage) {
			$u = $img->httpUrl ?? '';
			return $u !== '' ? (string) $u : (string) $img->url;
		}
		$file = $this->getPrimaryNonImageFile($item);
		if($file instanceof Pagefile) {
			$u = $file->httpUrl ?? '';
			return $u !== '' ? (string) $u : (string) $file->url;
		}
		return '';
	}

	/**
	 * Dupliziert Medien-Page inkl. Dateien (Clone, nicht rekursiv).
	 */
	public function ___duplicateMediaItem(int $id): Page {
		$p = $this->getMediaItem($id);
		if(!$p->id) return $this->wire->pages->newNullPage();
		$parent = $this->wire->pages->get($this->getRootPageId());
		if(!$parent->id) return $this->wire->pages->newNullPage();
		$copy = $this->wire->pages->clone($p, $parent, false);
		if(!$copy->id) return $this->wire->pages->newNullPage();
		$copy->of(false);
		$base = (string) ($p->mm_titel ?: $p->title);
		$copy->mm_titel = $base . ' (Kopie)';
		$copy->title = $copy->mm_titel;
		$copy->save();
		return $copy;
	}

	/**
	 * Primäre Datei (Bild oder Video/PDF) durch Upload ersetzen; Page-ID bleibt gleich.
	 *
	 * @return bool True bei Erfolg
	 */
	public function ___replacePrimaryFile(Page $item, string $tmpPath, string $originalName): bool {
		if(!$item->id) return false;
		if($tmpPath === '' || !is_uploaded_file($tmpPath)) return false;

		$ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

		$item->of(false);
		$img = $this->getPrimaryPageimage($item);
		if($img instanceof Pageimage) {
			$allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'];
			if(!in_array($ext, $allowed, true)) return false;
			$oldExt = strtolower(pathinfo($img->filename(), PATHINFO_EXTENSION));
			if(!$this->fileExtensionMatches($ext, $oldExt)) return false;
			if($ext === 'svg' && !$this->sanitizeSvgFile($tmpPath)) return false;
			if(!$img->replaceFile($tmpPath, true)) return false;
			$item->save();
			if($ext !== 'svg') {
				$this->createVariants($item);
				$this->ensureWebpAfterVariants($item);
			}
			return true;
		}
		$file = $this->getPrimaryNonImageFile($item);
		if($file instanceof Pagefile) {
			$allowed = ['pdf', 'mp4', 'mov'];
			if(!in_array($ext, $allowed, true)) return false;
			$oldExt = strtolower(pathinfo($file->filename(), PATHINFO_EXTENSION));
			if(!$this->fileExtensionMatches($ext, $oldExt)) return false;
			if(!$file->replaceFile($tmpPath, true)) return false;
			$item->save();
			return true;
		}
		return false;
	}

	/**
	 * @param string $newExt Endung der neuen Datei (Kleinbuchstaben)
	 * @param string $oldExt Endung der bestehenden Datei (Kleinbuchstaben)
	 */
	protected function fileExtensionMatches(string $newExt, string $oldExt): bool {
		if($newExt === $oldExt) return true;
		if($newExt === 'jpeg' && $oldExt === 'jpg') return true;
		if($newExt === 'jpg' && $oldExt === 'jpeg') return true;
		return false;
	}

	/** Medien-Item und zugehörige Dateien löschen. */
	public function ___deleteMedia(int $id): bool {
		$p = $this->getMediaItem($id);
		if($p->id) return $this->wire->pages->delete($p, true);
		return false;
	}

	/** Legt Felder, Templates und Root-Page an (Installation / Upgrade-Hook). */
	public function ___install(): void {
		$this->_installFelder();
		$this->_installFieldgroups();
		$this->_installTemplates();
		$this->_installKategorieTemplate();
		$this->_installRootPage();
	}

	protected function _installFelder(): void {
		$fields = $this->wire->fields;
		$pw = $this->wire;
		
		$fMap = [
			'mm_bild' => ['type' => 'FieldtypeImage', 'label' => 'Bild', 'ext' => 'jpg jpeg png gif webp svg'],
			'mm_datei' => ['type' => 'FieldtypeFile', 'label' => 'Datei', 'ext' => 'pdf mp4 mov'],
			'mm_titel' => ['type' => 'FieldtypeText', 'label' => 'Titel'],
			'mm_alt' => ['type' => 'FieldtypeText', 'label' => 'Alternativtext'],
			'mm_caption' => ['type' => 'FieldtypeText', 'label' => 'Beschriftung'],
			'mm_beschreibung' => ['type' => 'FieldtypeTextarea', 'label' => 'Beschreibung'],
			'mm_tags' => ['type' => 'FieldtypeText', 'label' => 'Tags'],
			'mm_typ' => ['type' => 'FieldtypeOptions', 'label' => 'Typ'],
			'mm_kategorie' => ['type' => 'FieldtypePage', 'label' => 'Kategorie'],
		];

		foreach($fMap as $name => $cfg) {
			$f = $fields->get($name);
			if(!$f) {
				$f = new Field();
				$f->type = $pw->modules->get($cfg['type']);
				$f->name = $name;
				$f->label = $cfg['label'];
				if(isset($cfg['ext'])) $f->extensions = $cfg['ext'];
				if($name === 'mm_bild') $f->maxFiles = 1;
				$f->save();
				if($name === 'mm_typ') $pw->modules->get('SelectableOptionManager')->setOptionsString($f, "1=bild\n2=video\n3=pdf");
			} else {
				if($name === 'mm_bild' && strpos((string) $f->extensions, 'svg') === false) {
					$f->extensions = trim($f->extensions . ' svg');
					$f->save();
				}
			}
		}
	}

	/**
	 * Stellt sicher, dass das Feld mm_bild auch in bestehenden Installationen die Erweiterung 'svg' besitzt.
	 */
	public function ensureSvgExtension(): void {
		$f = $this->wire->fields->get('mm_bild');
		if($f && strpos((string) $f->extensions, 'svg') === false) {
			$f->extensions = trim($f->extensions . ' svg');
			$f->save();
		}
	}

	protected function _installFieldgroups(): void {
		$fg = $this->wire->fieldgroups->get(self::ITEM_TEMPLATE) ?: new Fieldgroup();
		$fg->name = self::ITEM_TEMPLATE;
		foreach(['title', 'mm_bild', 'mm_datei', 'mm_titel', 'mm_alt', 'mm_caption', 'mm_beschreibung', 'mm_tags', 'mm_typ', 'mm_kategorie'] as $fn) {
			if($this->wire->fields->get($fn)) $fg->add($fn);
		}
		$fg->save();
	}

	protected function _installTemplates(): void {
		$t = $this->wire->templates->get(self::ITEM_TEMPLATE) ?: new Template();
		$t->name = self::ITEM_TEMPLATE;
		$t->fieldgroup = $this->wire->fieldgroups->get(self::ITEM_TEMPLATE);
		$t->save();
	}

	/**
	 * Template für Kategorie-Pages (Kinder der Medien-Root-Page).
	 */
	protected function _installKategorieTemplate(): void {
		$fg = $this->wire->fieldgroups->get(self::KATEGORIE_TEMPLATE) ?: new Fieldgroup();
		$fg->name = self::KATEGORIE_TEMPLATE;
		if($this->wire->fields->get('title')) {
			if(!$fg->hasField('title')) $fg->add('title');
		}
		$fg->save();

		$t = $this->wire->templates->get(self::KATEGORIE_TEMPLATE) ?: new Template();
		$t->name = self::KATEGORIE_TEMPLATE;
		$t->fieldgroup = $this->wire->fieldgroups->get(self::KATEGORIE_TEMPLATE);
		$t->save();
	}

	protected function _installRootPage(): Page {
		$admin = $this->wire->pages->get($this->wire->config->adminRootPageID);
		$root = $this->wire->pages->get("name=" . self::ROOT_NAME . ", include=all");
		if(!$root->id) {
			$root = $this->wire->pages->newPage(['template' => 'admin', 'parent' => $admin, 'name' => self::ROOT_NAME, 'title' => 'Medien Manager']);
			$root->save();
		}
		$cfg = $this->wire->modules->getModuleConfigData('bsProcessMedienManager');
		if(empty($cfg['rootPageID']) && $root->id) {
			$cfg['rootPageID'] = (int) $root->id;
			$this->wire->modules->saveModuleConfigData('bsProcessMedienManager', $cfg);
		}
		return $root;
	}

	/**
	 * Primäres Bild für Vorschau und Bildbearbeitung: zuerst mm_bild, sonst erstes Pageimage in mm_datei (Edge-Case).
	 */
	public function getPrimaryPageimage(Page $item): ?Pageimage {
		if($item->hasField('mm_bild')) {
			$mmBild = $item->mm_bild;
			$first = null;
			if($mmBild instanceof Pageimage) {
				$first = $mmBild;
			} elseif($mmBild instanceof Pageimages && $mmBild->count()) {
				$first = $mmBild->first();
			}
			return $first instanceof Pageimage ? $first : null;
		}
		if($item->hasField('mm_datei')) {
			$mmDatei = $item->mm_datei;
			$first = null;
			if($mmDatei instanceof Pageimage) {
				$first = $mmDatei;
			} elseif($mmDatei instanceof Pagefiles && $mmDatei->count()) {
				$first = $mmDatei->first();
			}
			return $first instanceof Pageimage ? $first : null;
		}
		return null;
	}

	/**
	 * Erste nicht-Bild-Datei (Video/PDF) für Icon-Vorschau, falls kein Pageimage gesetzt.
	 */
	public function getPrimaryNonImageFile(Page $item): ?Pagefile {
		if(!$item->hasField('mm_datei') || !\count($item->mm_datei)) return null;
		$first = $item->mm_datei->first();
		if($first instanceof Pageimage) return null;
		return $first instanceof Pagefile ? $first : null;
	}

	/**
	 * Primäre Datei für Anzeige (Dateiname, Größe): Bild bevorzugt, sonst Video/PDF.
	 */
	public function getPrimaryPagefileForDisplay(Page $item): ?Pagefile {
		$img = $this->getPrimaryPageimage($item);
		if($img instanceof Pagefile) return $img;
		return $this->getPrimaryNonImageFile($item);
	}

	/** Dateiname der primären Datei (für Karten-Label / Liste). */
	public function getPrimaryBasename(Page $item): string {
		$f = $this->getPrimaryPagefileForDisplay($item);
		return $f ? (string) $f->basename : '';
	}

	/** Lesbare Dateigröße der primären Datei. */
	public function getPrimaryFilesizeStr(Page $item): string {
		$f = $this->getPrimaryPagefileForDisplay($item);
		return $f ? $f->filesizeStr() : '';
	}

	/** Abmessungen z. B. „1920 × 1080“ oder leer (kein Rasterbild). */
	public function getPrimaryDimensionsStr(Page $item): string {
		$img = $this->getPrimaryPageimage($item);
		if($img instanceof Pageimage && $img->width > 0 && $img->height > 0) {
			return (string) $img->width . ' × ' . (string) $img->height;
		}
		return '';
	}

	/**
	 * Neue Kategorie unter der Medien-Root (oder unter $parentId).
	 */
	public function ___createKategorie(string $titel, int $parentId = 0): Page {
		$sanitizer = $this->wire->sanitizer;
		$titel     = $sanitizer->text($titel);
		if($titel === '') return $this->wire->pages->newNullPage();

		if($parentId <= 0) $parentId = $this->getRootPageId();
		$parent = $this->wire->pages->get((int) $parentId);
		if(!$parent->id) return $this->wire->pages->newNullPage();

		$baseName = $sanitizer->pageName($titel);
		if($baseName === '') $baseName = 'kategorie';
		$name = $this->wire->pages->names()->uniquePageName($baseName, null, ['parent' => $parent]);

		$p = $this->wire->pages->newPage([
			'template' => self::KATEGORIE_TEMPLATE,
			'parent'   => $parent,
			'name'     => $name,
			'title'    => $titel,
		]);
		$p->addStatus(Page::statusHidden);
		$p->save();
		return $p;
	}

	/**
	 * Kategorie löschen, nur wenn keine Medien-Items sie nutzen.
	 */
	public function ___deleteKategorie(int $id): bool {
		$p = $this->wire->pages->get((int) $id);
		if(!$p->id || $p->template->name !== self::KATEGORIE_TEMPLATE) return false;

		$n = $this->wire->pages->count('template=' . self::ITEM_TEMPLATE . ', mm_kategorie=' . (int) $id . ', include=all');
		if($n > 0) return false;

		$this->wire->pages->delete($p, true);
		return true;
	}

	/**
	 * Deinstallation: keine Medien-Pages und keine Felder/Templates löschen (Daten behalten).
	 *
	 * Leert nur die Modul-Konfiguration (`bsProcessMedienManager`), damit eine Neuinstallation
	 * nicht mit veralteter `rootPageID` o. Ä. arbeitet. Vollständiges Entfernen von Inhalten
	 * bleibt manuell bzw. über separate Wartung (siehe README.md).
	 */
	public function ___uninstall(): void {
		$this->wire->modules->saveModuleConfigData('bsProcessMedienManager', []);
	}

	// -----------------------------------------------------------------------
	// Verwendungsnachweis & Asset-Intelligence (Phase 11)
	// -----------------------------------------------------------------------

	/**
	 * Liefert alle Seiten, die das angegebene Medien-Item referenzieren.
	 * Durchsucht alle Felder vom Typ FieldtypeMedienManager sowie relevante FieldtypePage-Felder.
	 *
	 * @param int|Page $item Page oder ID des medienmanager-item
	 * @return PageArray
	 */
	public function ___getReferencingPages($item): PageArray {
		$id = $item instanceof Page ? (int) $item->id : (int) $item;
		$out = $this->wire->pages->newPageArray();
		if($id <= 0) return $out;

		$pageIds = [];

		// 1. Alle FieldtypeMedienManager Felder durchsuchen
		$fields = $this->wire->fields->find('type=FieldtypeMedienManager');
		foreach($fields as $field) {
			$table = $this->wire->database->escapeTable("field_{$field->name}");
			try {
				$stmt = $this->wire->database->prepare("SELECT DISTINCT pages_id FROM `{$table}` WHERE data = :id");
				$stmt->execute([':id' => $id]);
				while($row = $stmt->fetch(\PDO::FETCH_NUM)) {
					$pageIds[] = (int) $row[0];
				}
			} catch(\Throwable $e) {}
		}

		// 2. PageReference-Felder prüfen, die medienmanager-item nutzen
		$tpl = $this->wire->templates->get(self::ITEM_TEMPLATE);
		if($tpl && $tpl->id) {
			$pageFields = $this->wire->fields->find('type=FieldtypePage');
			foreach($pageFields as $field) {
				if((int) $field->get('template_id') === (int) $tpl->id || (is_array($field->get('template_ids')) && in_array($tpl->id, $field->get('template_ids')))) {
					$table = $this->wire->database->escapeTable("field_{$field->name}");
					try {
						$stmt = $this->wire->database->prepare("SELECT DISTINCT pages_id FROM `{$table}` WHERE data = :id");
						$stmt->execute([':id' => $id]);
						while($row = $stmt->fetch(\PDO::FETCH_NUM)) {
							$pageIds[] = (int) $row[0];
						}
					} catch(\Throwable $e) {}
				}
			}
		}

		$pageIds = array_unique(array_filter($pageIds, fn($pid) => $pid > 0));
		if(!empty($pageIds)) {
			// Nur aktive Seiten laden (nicht im Papierkorb)
			$out = $this->wire->pages->find("id=" . implode('|', $pageIds) . ", status<" . Page::statusTrash . ", include=all");
		}

		return $out;
	}

	/**
	 * Liefert alle eindeutigen Medien-IDs zurück, die aktuell auf mindestens einer aktiven Seite referenziert sind.
	 *
	 * @return int[]
	 */
	public function ___getAllUsedMediaIds(): array {
		$mediaIds = [];
		$fields = $this->wire->fields->find('type=FieldtypeMedienManager');
		foreach($fields as $field) {
			$table = $this->wire->database->escapeTable("field_{$field->name}");
			try {
				$sql = "SELECT DISTINCT f.data FROM `{$table}` AS f 
				        JOIN pages AS p ON p.id = f.pages_id 
				        WHERE (p.status & " . Page::statusTrash . ") = 0";
				$stmt = $this->wire->database->query($sql);
				while($row = $stmt->fetch(\PDO::FETCH_NUM)) {
					$mid = (int) $row[0];
					if($mid > 0) $mediaIds[$mid] = $mid;
				}
			} catch(\Throwable $e) {}
		}
		return array_values($mediaIds);
	}

	/**
	 * Liefert ein assoziatives Array [media_id => anzahl_verwendungen] für alle Medien.
	 *
	 * @return array<int, int>
	 */
	public function ___getAllMediaUsageCounts(): array {
		$counts = [];
		$fields = $this->wire->fields->find('type=FieldtypeMedienManager');
		foreach($fields as $field) {
			$table = $this->wire->database->escapeTable("field_{$field->name}");
			try {
				$sql = "SELECT f.data, COUNT(DISTINCT f.pages_id) AS cnt FROM `{$table}` AS f 
				        JOIN pages AS p ON p.id = f.pages_id 
				        WHERE (p.status & " . Page::statusTrash . ") = 0 
				        GROUP BY f.data";
				$stmt = $this->wire->database->query($sql);
				while($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
					$mid = (int) $row['data'];
					$cnt = (int) $row['cnt'];
					$counts[$mid] = ($counts[$mid] ?? 0) + $cnt;
				}
			} catch(\Throwable $e) {}
		}
		return $counts;
	}

	/**
	 * Prüft schnell, ob ein Medien-Item auf mindestens einer aktiven Seite in Benutzung ist.
	 *
	 * @param int|Page $item
	 * @return bool
	 */
	public function isMediaInUse($item): bool {
		$pages = $this->getReferencingPages($item);
		return $pages->count() > 0;
	}

	// -----------------------------------------------------------------------
	// Focal Point & Bildbearbeitung (Phase 11.3)
	// -----------------------------------------------------------------------

	/**
	 * Liest den Fokuspunkt für das primäre Pageimage aus.
	 *
	 * @param Page $item
	 * @return array{top: float, left: float, default: bool, str: string}
	 */
	public function getMediaFocus(Page $item): array {
		$img = $this->getPrimaryPageimage($item);
		if(!$img instanceof Pageimage) {
			return ['top' => 50.0, 'left' => 50.0, 'default' => true, 'str' => '50 50'];
		}
		$f = $img->focus();
		return [
			'top'     => (float) ($f['top'] ?? 50.0),
			'left'    => (float) ($f['left'] ?? 50.0),
			'default' => (bool) ($f['default'] ?? true),
			'str'     => (string) ($f['str'] ?? '50 50'),
		];
	}

	/**
	 * Setzt den Fokuspunkt auf dem primären Pageimage, speichert die Page und
	 * löscht vorhandene Bildvariationen, damit Zuschnitte sofort neu berechnet werden.
	 *
	 * @param Page $item
	 * @param float $top 0 bis 100%
	 * @param float $left 0 bis 100%
	 * @return bool
	 */
	public function ___setMediaFocus(Page $item, float $top, float $left): bool {
		if(!$item->id) return false;
		$item->of(false);
		$img = $this->getPrimaryPageimage($item);
		if(!$img instanceof Pageimage) return false;

		$top  = max(0.0, min(100.0, round($top, 1)));
		$left = max(0.0, min(100.0, round($left, 1)));

		$img->focus($top, $left);
		$item->save();
		$img->removeVariations();
		return true;
	}

	// -----------------------------------------------------------------------
	// Sichere SVG-Unterstützung (Phase 11.4)
	// -----------------------------------------------------------------------

	/**
	 * Bereinigt eine SVG-Datei gegen Stored XSS, XXE und bösartige Skripte/Tags.
	 *
	 * @param string $filePath Vollständiger Pfad zur SVG-Datei
	 * @return bool True bei erfolgreicher Bereinigung, False bei Erkennung irreversibler Bedrohungen oder defektem XML
	 */
	public function sanitizeSvgFile(string $filePath): bool {
		if(!is_file($filePath) || !is_readable($filePath) || !is_writable($filePath)) {
			return false;
		}

		$content = file_get_contents($filePath);
		if($content === false || trim($content) === '') {
			return false;
		}

		// 1. Verhindere XXE: DOCTYPE mit ENTITY Deklarationen oder SYSTEM/PUBLIC URLs komplett verbieten
		if(preg_match('/<!ENTITY/i', $content) || preg_match('/<!DOCTYPE[^>]*\[/i', $content) || preg_match('/SYSTEM\s+["\']/i', $content)) {
			$this->wire->log->error('MM SVG Sanitizer: XXE Attempt detected in ' . basename($filePath));
			return false;
		}

		// 2. Parsen mit DOMDocument unter striktem Ausschluss externer Netzwerkanfragen
		$dom = new \DOMDocument();
		$dom->formatOutput = false;
		$dom->preserveWhiteSpace = true;

		$prevEntityLoader = null;
		if(\PHP_VERSION_ID < 80000 && function_exists('libxml_disable_entity_loader')) {
			$prevEntityLoader = libxml_disable_entity_loader(true);
		}

		$prevInternalErrors = libxml_use_internal_errors(true);
		libxml_clear_errors();

		$loaded = $dom->loadXML($content, LIBXML_NONET);

		if($prevEntityLoader !== null && function_exists('libxml_disable_entity_loader')) {
			libxml_disable_entity_loader($prevEntityLoader);
		}

		libxml_use_internal_errors($prevInternalErrors);

		if(!$loaded) {
			$this->wire->log->error('MM SVG Sanitizer: Ungültiges XML in ' . basename($filePath));
			return false;
		}

		// Root-Element prüfen
		if(!$dom->documentElement || strtolower($dom->documentElement->nodeName) !== 'svg') {
			$this->wire->log->error('MM SVG Sanitizer: Root-Element ist nicht <svg> in ' . basename($filePath));
			return false;
		}

		// 3. Unerlaubte Tags entfernen
		$disallowedTags = [
			'script', 'foreignobject', 'iframe', 'object', 'embed', 'applet',
			'meta', 'link', 'form', 'input', 'button', 'select', 'textarea',
			'audio', 'video'
		];

		$removeNodes = [];
		$xpath = new \DOMXPath($dom);
		$allElements = $xpath->query('//*');
		if($allElements !== false) {
			foreach($allElements as $node) {
				if(!($node instanceof \DOMElement)) continue;

				$tagName = strtolower($node->localName ?: $node->nodeName);
				if(in_array($tagName, $disallowedTags, true)) {
					$removeNodes[] = $node;
					continue;
				}

				if($node->hasAttributes()) {
					$removeAttrs = [];
					foreach($node->attributes as $attr) {
						$attrName = strtolower($attr->nodeName);
						$attrValue = trim((string) $attr->nodeValue);

						// on* Event-Handler entfernen
						if(str_starts_with($attrName, 'on') || preg_match('/^on[a-z]/i', $attrName)) {
							$removeAttrs[] = $attr->nodeName;
							continue;
						}

						// href, xlink:href, src auf bösartige Protokolle prüfen
						if(in_array($attrName, ['href', 'xlink:href', 'src'], true) || str_ends_with($attrName, ':href')) {
							$cleanVal = preg_replace('/[\x00-\x20\s]+/', '', strtolower($attrValue));
							if(
								str_starts_with($cleanVal, 'javascript:') ||
								str_starts_with($cleanVal, 'vbscript:') ||
								(str_starts_with($cleanVal, 'data:') && !str_starts_with($cleanVal, 'data:image/'))
							) {
								$removeAttrs[] = $attr->nodeName;
								continue;
							}
						}

						// style-Attribut säubern
						if($attrName === 'style') {
							$cleanStyle = preg_replace('/[\x00-\x20\s]+/', '', strtolower($attrValue));
							if(
								str_contains($cleanStyle, 'expression(') ||
								str_contains($cleanStyle, 'javascript:') ||
								str_contains($cleanStyle, 'behavior:') ||
								str_contains($cleanStyle, '@import')
							) {
								$removeAttrs[] = $attr->nodeName;
								continue;
							}
						}
					}

					foreach($removeAttrs as $aName) {
						$node->removeAttribute($aName);
					}
				}
			}
		}

		foreach($removeNodes as $node) {
			if($node->parentNode) {
				$node->parentNode->removeChild($node);
			}
		}

		$cleanXml = $dom->saveXML();
		if($cleanXml === false || trim($cleanXml) === '') {
			return false;
		}

		return (bool) file_put_contents($filePath, $cleanXml);
	}

	// -----------------------------------------------------------------------
	// Frontend-Rendering & Responsive Picture Pipeline (Phase 10)
	// -----------------------------------------------------------------------

	/**
	 * Prüft, ob eine übergebene Page ein Medien-Item des Managers ist.
	 */
	public function isMediaItem(?Page $page): bool {
		return $page instanceof Page && $page->id && $page->template && $page->template->name === self::ITEM_TEMPLATE;
	}

	/**
	 * Zentraler Einstiegspunkt für die Ausgabe eines Mediums im Frontend.
	 * Unterstützt Bilder (responsive <picture> mit WebP), Videos (<video>) und PDFs (Download-Link/Embed).
	 *
	 * @param Page $media Die medienmanager-item Page
	 * @param array<string, mixed> $options Ausgabe-Optionen
	 * @return string HTML
	 */
	public function ___renderMedia(Page $media, array $options = []): string {
		if(!$this->isMediaItem($media)) return '';

		$typ = $this->getTypString($media);

		if($this->hasRenderableImage($media)) {
			$img = $this->getPrimaryPageimage($media);
			if($img instanceof Pageimage) {
				return $this->renderPicture($img, $options, $media);
			}
		}

		if($typ === 'video') {
			return $this->renderVideo($media, $options);
		}

		if($typ === 'pdf') {
			return $this->renderPdf($media, $options);
		}

		// Generischer Fallback für andere Dateitypen
		$pubUrl = $this->getPublicFileUrl($media);
		if($pubUrl === '') return '';
		$label = $this->getAccessibleLabel($media);
		$class = isset($options['class']) ? ' class="' . htmlspecialchars((string) $options['class'], ENT_QUOTES, 'UTF-8') . '"' : '';
		return "<a href='" . htmlspecialchars($pubUrl, ENT_QUOTES, 'UTF-8') . "'{$class}>" . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . "</a>";
	}

	/**
	 * Rendert ein Pageimage als responsives HTML5 <picture> Element mit WebP- und Fallback-Quellen.
	 *
	 * @param Pageimage $img
	 * @param array<string, mixed> $options
	 * @param Page|null $media
	 * @return string HTML
	 */
	public function ___renderPicture(Pageimage $img, array $options = [], ?Page $media = null): string {
		$sanitizer = $this->wire->sanitizer;

		$origW = (int) $img->width;
		$origH = (int) $img->height;

		$targetW = isset($options['width']) ? (int) $options['width'] : 1200;
		if($targetW <= 0 || ($origW > 0 && $targetW > $origW)) {
			$targetW = $origW > 0 ? $origW : 1200;
		}

		$targetH  = isset($options['height']) ? (int) $options['height'] : 0;
		$crop     = $options['crop'] ?? false;
		$useWebp  = (bool) ($options['webp'] ?? true);
		$usePic   = (bool) ($options['picture'] ?? true);
		$loading  = in_array(($options['loading'] ?? 'lazy'), ['lazy', 'eager'], true) ? $options['loading'] : 'lazy';
		$decoding = $options['decoding'] ?? 'async';

		// Alt-Text
		$alt = '';
		if(isset($options['alt'])) {
			$alt = (string) $options['alt'];
		} elseif($media) {
			$alt = $this->getAccessibleLabel($media);
		} else {
			$alt = (string) $img->description;
		}
		$altEsc = htmlspecialchars($alt, ENT_QUOTES | ENT_HTML5, 'UTF-8');

		// Caption ermitteln
		$captionText = '';
		if(isset($options['caption']) && is_string($options['caption'])) {
			$captionText = $options['caption'];
		} elseif($media && (!isset($options['caption']) || $options['caption'] === true)) {
			$captionText = $this->getCaption($media);
		}
		$hasCaption = $captionText !== '';

		// SVG-Sonderbehandlung: Vektorgrafiken skalieren verlustfrei und brauchen keine Raster-Breakpoints
		if(strtolower((string) $img->ext) === 'svg') {
			$wAttr = $targetW > 0 ? " width='{$targetW}'" : '';
			$hAttr = $targetH > 0 ? " height='{$targetH}'" : '';
			$classAttr = isset($options['class']) ? ' class="' . htmlspecialchars((string) $options['class'], ENT_QUOTES, 'UTF-8') . '"' : '';
			$imgHtml = "<img src='" . htmlspecialchars($img->url, ENT_QUOTES, 'UTF-8') . "' alt='{$altEsc}'{$wAttr}{$hAttr}{$classAttr} loading='{$loading}'>";
			if($hasCaption) {
				$figClass = isset($options['figureClass']) ? ' class="' . htmlspecialchars((string) $options['figureClass'], ENT_QUOTES, 'UTF-8') . '"' : '';
				$capClass = isset($options['captionClass']) ? ' class="' . htmlspecialchars((string) $options['captionClass'], ENT_QUOTES, 'UTF-8') . '"' : '';
				$capEsc   = htmlspecialchars($captionText, ENT_QUOTES | ENT_HTML5, 'UTF-8');
				return "<figure{$figClass} role=\"group\">\n\t{$imgHtml}\n\t<figcaption{$capClass}>{$capEsc}</figcaption>\n</figure>";
			}
			return $imgHtml;
		}

		// Hauptbild (Fallback <img>)
		$mainSizerOptions = [
			'cropping'  => $crop,
			'upscaling' => false,
		];
		$mainThumb = ($targetW < $origW || ($targetH > 0 && $targetH < $origH))
			? $img->size($targetW, $targetH, $mainSizerOptions)
			: $img;

		$imgW = (int) $mainThumb->width;
		$imgH = (int) $mainThumb->height;
		if($imgW <= 0) $imgW = $targetW > 0 ? $targetW : 800;
		if($imgH <= 0) $imgH = $targetH > 0 ? $targetH : 600;

		// Breakpoint-Breiten für srcset ermitteln
		$breakpoints = $options['widths'] ?? [480, 768, 1024, 1440];
		if(!is_array($breakpoints)) $breakpoints = [480, 768, 1024, 1440];
		$breakpoints[] = $imgW;
		$breakpoints = array_unique(array_filter(array_map('intval', $breakpoints), fn($w) => $w > 0 && ($origW === 0 || $w <= $origW * 1.25)));
		sort($breakpoints);

		// Sizes-Attribut
		if(isset($options['sizes']) && $options['sizes'] !== '') {
			$sizesAttr = htmlspecialchars((string) $options['sizes'], ENT_QUOTES, 'UTF-8');
		} else {
			$sizesAttr = "(max-width: {$imgW}px) 100vw, {$imgW}px";
		}

		// Srcset-Listen erzeugen
		$srcsetDefault = [];
		$srcsetWebp    = [];

		foreach($breakpoints as $w) {
			$h = 0;
			if($targetH > 0 && $targetW > 0) {
				$h = (int) round(($targetH / $targetW) * $w);
			}
			$thumb = ($w === $origW && $h === 0) ? $img : $img->size($w, $h, $mainSizerOptions);
			$thumbUrl = $thumb->url;
			$srcsetDefault[] = "{$thumbUrl} {$thumb->width}w";

			if($useWebp) {
				try {
					if($this->ensureWebpForPageimage($thumb)) {
						$webp = $thumb->webp();
						if($webp && $webp->exists()) {
							$srcsetWebp[] = "{$webp->url} {$thumb->width}w";
						}
					}
				} catch(\Throwable $e) {
					// Fallback ignorieren
				}
			}
		}

		// <img> Tag zusammenbauen
		$imgClass = isset($options['class']) ? ' class="' . htmlspecialchars((string) $options['class'], ENT_QUOTES, 'UTF-8') . '"' : '';
		
		$extraAttrs = '';
		if(isset($options['attrs']) && is_array($options['attrs'])) {
			foreach($options['attrs'] as $k => $v) {
				$attrName = $sanitizer->name((string) $k);
				if($attrName !== '') {
					$extraAttrs .= " {$attrName}=\"" . htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8') . "\"";
				}
			}
		}

		$focusStyle = '';
		if(!empty($options['focus_css'])) {
			$foc = $img->focus();
			$focusStyle = ' style="object-position: ' . $foc['left'] . '% ' . $foc['top'] . '%;"';
		}

		$mainImgUrl = $mainThumb->url;
		$imgHtml = "<img src=\"{$mainImgUrl}\" width=\"{$imgW}\" height=\"{$imgH}\" alt=\"{$altEsc}\" loading=\"{$loading}\" decoding=\"{$decoding}\"{$imgClass}{$focusStyle}{$extraAttrs}>";

		if(!$usePic || (empty($srcsetWebp) && count($srcsetDefault) <= 1)) {
			$out = $imgHtml;
		} else {
			$picClass = isset($options['pictureClass']) ? ' class="' . htmlspecialchars((string) $options['pictureClass'], ENT_QUOTES, 'UTF-8') . '"' : '';
			$out = "<picture{$picClass}>\n";

			if(!empty($srcsetWebp)) {
				$srcsetWebpStr = implode(', ', $srcsetWebp);
				$out .= "\t<source type=\"image/webp\" srcset=\"{$srcsetWebpStr}\" sizes=\"{$sizesAttr}\">\n";
			}

			if(count($srcsetDefault) > 1) {
				$srcsetDefaultStr = implode(', ', $srcsetDefault);
				$out .= "\t<source srcset=\"{$srcsetDefaultStr}\" sizes=\"{$sizesAttr}\">\n";
			}

			$out .= "\t{$imgHtml}\n";
			$out .= "</picture>";
		}

		if($hasCaption) {
			$figClass = isset($options['figureClass']) ? ' class="' . htmlspecialchars((string) $options['figureClass'], ENT_QUOTES, 'UTF-8') . '"' : '';
			$capClass = isset($options['captionClass']) ? ' class="' . htmlspecialchars((string) $options['captionClass'], ENT_QUOTES, 'UTF-8') . '"' : '';
			$capEsc   = htmlspecialchars($captionText, ENT_QUOTES | ENT_HTML5, 'UTF-8');
			$out = "<figure{$figClass} role=\"group\">\n\t{$out}\n\t<figcaption{$capClass}>{$capEsc}</figcaption>\n</figure>";
		}

		return $out;
	}

	/**
	 * Rendert ein Video-Medium als HTML5 <video> Tag.
	 */
	public function ___renderVideo(Page $media, array $options = []): string {
		$url = $this->getPublicFileUrl($media);
		if($url === '') return '';

		$urlEsc   = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
		$controls = ($options['controls'] ?? true) ? ' controls' : '';
		$autoplay = ($options['autoplay'] ?? false) ? ' autoplay' : '';
		$loop     = ($options['loop'] ?? false) ? ' loop' : '';
		$muted    = ($options['muted'] ?? false) ? ' muted' : '';
		$preload  = isset($options['preload']) ? ' preload="' . htmlspecialchars((string) $options['preload'], ENT_QUOTES, 'UTF-8') . '"' : ' preload="metadata"';
		$class    = isset($options['class']) ? ' class="' . htmlspecialchars((string) $options['class'], ENT_QUOTES, 'UTF-8') . '"' : '';

		$posterAttr = '';
		if(isset($options['poster']) && $options['poster'] !== '') {
			$posterAttr = ' poster="' . htmlspecialchars((string) $options['poster'], ENT_QUOTES, 'UTF-8') . '"';
		} elseif($this->hasRenderableImage($media)) {
			$img = $this->getPrimaryPageimage($media);
			if($img) $posterAttr = ' poster="' . htmlspecialchars($img->url, ENT_QUOTES, 'UTF-8') . '"';
		}

		$widthAttr  = isset($options['width']) ? ' width="' . (int) $options['width'] . '"' : '';
		$heightAttr = isset($options['height']) ? ' height="' . (int) $options['height'] . '"' : '';

		$videoHtml = "<video src=\"{$urlEsc}\"{$controls}{$autoplay}{$loop}{$muted}{$preload}{$posterAttr}{$widthAttr}{$heightAttr}{$class}></video>";

		$captionText = '';
		if(isset($options['caption']) && is_string($options['caption'])) {
			$captionText = $options['caption'];
		} elseif(!isset($options['caption']) || $options['caption'] === true) {
			$captionText = $this->getCaption($media);
		}

		if($captionText !== '') {
			$figClass = isset($options['figureClass']) ? ' class="' . htmlspecialchars((string) $options['figureClass'], ENT_QUOTES, 'UTF-8') . '"' : '';
			$capClass = isset($options['captionClass']) ? ' class="' . htmlspecialchars((string) $options['captionClass'], ENT_QUOTES, 'UTF-8') . '"' : '';
			$capEsc   = htmlspecialchars($captionText, ENT_QUOTES | ENT_HTML5, 'UTF-8');
			return "<figure{$figClass} role=\"group\">\n\t{$videoHtml}\n\t<figcaption{$capClass}>{$capEsc}</figcaption>\n</figure>";
		}

		return $videoHtml;
	}

	/**
	 * Rendert ein PDF-Medium als Download-Link oder Iframe.
	 */
	public function ___renderPdf(Page $media, array $options = []): string {
		$url = $this->getPublicFileUrl($media);
		if($url === '') return '';

		$urlEsc = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
		$class  = isset($options['class']) ? ' class="' . htmlspecialchars((string) $options['class'], ENT_QUOTES, 'UTF-8') . '"' : '';

		if(!empty($options['embed'])) {
			$w = isset($options['width']) ? ' width="' . (int) $options['width'] . '"' : ' width="100%"';
			$h = isset($options['height']) ? ' height="' . (int) $options['height'] . '"' : ' height="600"';
			return "<iframe src=\"{$urlEsc}\"{$w}{$h}{$class} frameborder=\"0\"></iframe>";
		}

		$label = $options['label'] ?? $this->getAccessibleLabel($media);
		$labelEsc = htmlspecialchars($label, ENT_QUOTES | ENT_HTML5, 'UTF-8');

		$sizeInfo = '';
		if($options['showSize'] ?? true) {
			$sz = $this->getPrimaryFilesizeStr($media);
			if($sz !== '') $sizeInfo = ' <span class="mm-pdf-size">(' . htmlspecialchars($sz, ENT_QUOTES, 'UTF-8') . ')</span>';
		}

		return "<a href=\"{$urlEsc}\" target=\"_blank\" rel=\"noopener\"{$class}>{$labelEsc}{$sizeInfo}</a>";
	}

	/**
	 * Rendert eine PageArray-Sammlung von Medien-Items.
	 */
	public function ___renderMediaCollection(PageArray $items, array $options = []): string {
		$out = [];
		foreach($items as $item) {
			if(!$this->isMediaItem($item)) continue;
			$out[] = $this->renderMedia($item, $options);
		}
		if(empty($out)) return '';

		$wrapper = $options['wrapper'] ?? 'div';
		if(!$wrapper) {
			return implode("\n", $out);
		}

		$wrapClass = isset($options['collectionClass'])
			? ' class="' . htmlspecialchars((string) $options['collectionClass'], ENT_QUOTES, 'UTF-8') . '"'
			: ' class="mm-media-collection"';

		$tag = htmlspecialchars((string) $wrapper, ENT_QUOTES, 'UTF-8');
		return "<{$tag}{$wrapClass}>\n\t" . implode("\n\t", $out) . "\n</{$tag}>";
	}

	/**
	 * Registriert alle Frontend-Hooks für $media->render(), Properties und PageArray.
	 */
	public static function registerFrontendHooks(Wire $wire): void {
		static $registered = false;
		if($registered) return;
		$registered = true;

		// 1. Page::renderMedia($options = [])
		$wire->addHookMethod('Page::renderMedia', function(HookEvent $event) use ($wire) {
			$page = $event->object;
			if(!$page instanceof Page) return;
			$api = new self($wire);
			if(!$api->isMediaItem($page)) return;
			$options = $event->arguments(0);
			if(!is_array($options)) $options = [];
			$event->return = $api->renderMedia($page, $options);
		});

		// 2. Page::render hook — fängt $media->render($options) ab, wenn Template medienmanager-item ist
		$wire->addHookBefore('Page::render', function(HookEvent $event) use ($wire) {
			$page = $event->object;
			if(!$page instanceof Page) return;
			if($page->template && $page->template->name === self::ITEM_TEMPLATE) {
				$api = new self($wire);
				$options = $event->arguments(0);
				if(!is_array($options)) $options = [];
				$event->replace = true;
				$event->return = $api->renderMedia($page, $options);
			}
		});

		// 3. PageArray::renderMedia($options = [])
		try {
			$wire->addHookMethod('PageArray::renderMedia', function(HookEvent $event) use ($wire) {
				$pageArray = $event->object;
				if(!$pageArray instanceof PageArray || !$pageArray->count()) return;
				$api = new self($wire);
				$options = $event->arguments(0);
				if(!is_array($options)) $options = [];
				$event->return = $api->renderMediaCollection($pageArray, $options);
			});
		} catch(\Throwable $e) {}

		// 4. PageArray::render hook — fängt $pageArray->render($options) ab, wenn PageArray Medien-Items enthält
		try {
			$wire->addHookBefore('PageArray::render', function(HookEvent $event) use ($wire) {
				$pageArray = $event->object;
				if(!$pageArray instanceof PageArray || !$pageArray->count()) return;
				$first = $pageArray->first();
				if($first && $first->template && $first->template->name === self::ITEM_TEMPLATE) {
					$api = new self($wire);
					$options = $event->arguments(0);
					if(!is_array($options)) $options = [];
					$event->replace = true;
					$event->return = $api->renderMediaCollection($pageArray, $options);
				}
			});
		} catch(\Throwable $e) {}

		// 5. Helper-Methoden & Properties auf Page (nur für medienmanager-item)
		$wire->addHookMethod('Page::mediaUrl', function(HookEvent $event) use ($wire) {
			$page = $event->object;
			if(!$page instanceof Page || $page->template->name !== self::ITEM_TEMPLATE) return;
			$api = new self($wire);
			$w = (int) $event->arguments(0);
			$h = (int) $event->arguments(1);
			if($w > 0 && $api->hasRenderableImage($page)) {
				$img = $api->getPrimaryPageimage($page);
				if($img) {
					$event->return = $img->size($w, $h ?: 0)->url;
					return;
				}
			}
			$event->return = $api->getPublicFileUrl($page);
		});

		$wire->addHookProperty('Page::mediaUrl', function(HookEvent $event) use ($wire) {
			$page = $event->object;
			if(!$page instanceof Page || $page->template->name !== self::ITEM_TEMPLATE) return;
			$api = new self($wire);
			$event->return = $api->getPublicFileUrl($page);
		});

		$wire->addHookProperty('Page::fileUrl', function(HookEvent $event) use ($wire) {
			$page = $event->object;
			if(!$page instanceof Page || $page->template->name !== self::ITEM_TEMPLATE) return;
			$api = new self($wire);
			$event->return = $api->getPublicFileUrl($page);
		});

		$wire->addHookProperty('Page::alt', function(HookEvent $event) use ($wire) {
			$page = $event->object;
			if(!$page instanceof Page || $page->template->name !== self::ITEM_TEMPLATE) return;
			$api = new self($wire);
			$event->return = $api->getAccessibleLabel($page);
		});

		$wire->addHookProperty('Page::caption', function(HookEvent $event) use ($wire) {
			$page = $event->object;
			if(!$page instanceof Page || $page->template->name !== self::ITEM_TEMPLATE) return;
			$api = new self($wire);
			$event->return = $api->getCaption($page);
		});

		$wire->addHookProperty('Page::isImage', function(HookEvent $event) use ($wire) {
			$page = $event->object;
			if(!$page instanceof Page || $page->template->name !== self::ITEM_TEMPLATE) return;
			$api = new self($wire);
			$event->return = $api->hasRenderableImage($page);
		});

		$wire->addHookProperty('Page::isVideo', function(HookEvent $event) use ($wire) {
			$page = $event->object;
			if(!$page instanceof Page || $page->template->name !== self::ITEM_TEMPLATE) return;
			$api = new self($wire);
			$event->return = $api->getTypString($page) === 'video';
		});

		$wire->addHookProperty('Page::isPdf', function(HookEvent $event) use ($wire) {
			$page = $event->object;
			if(!$page instanceof Page || $page->template->name !== self::ITEM_TEMPLATE) return;
			$api = new self($wire);
			$event->return = $api->getTypString($page) === 'pdf';
		});

		$wire->addHookProperty('Page::pageimage', function(HookEvent $event) use ($wire) {
			$page = $event->object;
			if(!$page instanceof Page || $page->template->name !== self::ITEM_TEMPLATE) return;
			$api = new self($wire);
			$event->return = $api->getPrimaryPageimage($page);
		});

		$wire->addHookProperty('Page::dimensions', function(HookEvent $event) use ($wire) {
			$page = $event->object;
			if(!$page instanceof Page || $page->template->name !== self::ITEM_TEMPLATE) return;
			$api = new self($wire);
			$event->return = $api->getPrimaryDimensionsStr($page);
		});

		$wire->addHookProperty('Page::filesize', function(HookEvent $event) use ($wire) {
			$page = $event->object;
			if(!$page instanceof Page || $page->template->name !== self::ITEM_TEMPLATE) return;
			$api = new self($wire);
			$event->return = $api->getPrimaryFilesizeStr($page);
		});

		$wire->addHookProperty('Page::focus', function(HookEvent $event) use ($wire) {
			$page = $event->object;
			if(!$page instanceof Page || $page->template->name !== self::ITEM_TEMPLATE) return;
			$api = new self($wire);
			$event->return = $api->getMediaFocus($page);
		});

		$wire->addHookProperty('Page::isSvg', function(HookEvent $event) use ($wire) {
			$page = $event->object;
			if(!$page instanceof Page || $page->template->name !== self::ITEM_TEMPLATE) return;
			$api = new self($wire);
			$img = $api->getPrimaryPageimage($page);
			$event->return = ($img instanceof Pageimage && strtolower((string) $img->ext) === 'svg');
		});

		$wire->addHookProperty('Page::usedOnPages', function(HookEvent $event) use ($wire) {
			$page = $event->object;
			if(!$page instanceof Page || $page->template->name !== self::ITEM_TEMPLATE) return;
			$api = new self($wire);
			$event->return = $api->getReferencingPages($page);
		});

		$wire->addHookProperty('Page::usageCount', function(HookEvent $event) use ($wire) {
			$page = $event->object;
			if(!$page instanceof Page || $page->template->name !== self::ITEM_TEMPLATE) return;
			$api = new self($wire);
			$event->return = $api->getReferencingPages($page)->count();
		});

		$wire->addHookMethod('Page::focalUrl', function(HookEvent $event) use ($wire) {
			$page = $event->object;
			if(!$page instanceof Page || $page->template->name !== self::ITEM_TEMPLATE) return;
			$api = new self($wire);
			$img = $api->getPrimaryPageimage($page);
			if(!$img instanceof Pageimage) {
				$event->return = '';
				return;
			}
			if(strtolower((string) $img->ext) === 'svg') {
				$event->return = $img->url;
				return;
			}
			$w = (int) ($event->arguments(0) ?: 800);
			$h = (int) ($event->arguments(1) ?: 600);
			$opts = (array) ($event->arguments(2) ?: []);
			$opts['cropping'] = true;
			$event->return = $img->size($w, $h, $opts)->url;
		});
	}
}
