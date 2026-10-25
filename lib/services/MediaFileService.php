<?php namespace ProcessWire;

/**
 * Primärdateien, URLs, Metadaten-Helfer, Datei ersetzen.
 */
class MediaFileService extends MediaManagerService {

	public function getTypString(Page $item): string {
		if (!$item->id || !$item->mm_typ) return 'bild';
		return strtolower((string) $item->mm_typ->title);
	}

	/**
	 * Text für `alt` und Screenreader: zuerst mm_alt, sonst sinnvoller Titel (SEO/A11y).
	 */
	public function getAccessibleLabel(Page $item): string {
		if(!$item->id) return '';
		$san = $this->api->sanitizer;
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

		return $this->api->sanitizer->text((string) $item->mm_caption);
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
	public function replacePrimaryFile(Page $item, string $tmpPath, string $originalName): bool {
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
			if($ext === 'svg' && !$this->api->sanitizeSvgFile($tmpPath)) return false;
			if(!$img->replaceFile($tmpPath, true)) return false;
			$item->save();
			if($ext !== 'svg') {
				$this->api->createVariants($item);
				$this->api->ensureWebpAfterVariants($item);
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
	public function fileExtensionMatches(string $newExt, string $oldExt): bool {
		if($newExt === $oldExt) return true;
		if($newExt === 'jpeg' && $oldExt === 'jpg') return true;
		if($newExt === 'jpg' && $oldExt === 'jpeg') return true;
		return false;
	}
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
}
