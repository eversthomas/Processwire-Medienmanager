<?php namespace ProcessWire;

/**
 * Variationen, Master-Rotate/Resize, WebP, Focal Point, Thumbnails.
 */
class MediaImageService extends MediaManagerService {

	public function ensureWebpAfterVariants(Page $p): void {
		$pid = (int) $p->id;
		if($pid <= 0) return;
		$fresh = $this->api->pages->getFresh($pid);
		if(!$fresh->id) return;
		$fresh->of(false);
		$img = $this->api->getPrimaryPageimage($fresh);
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
	public function createVariants(Page $p): void {
		$pid = (int) $p->id;
		if($pid <= 0) return;

		$page = $this->api->pages->getFresh($pid);
		if(!$page->id || !$page->hasField('mm_bild') || !count($page->mm_bild)) {
			$this->api->log->save('medienmanager', "createVariants: keine mm_bild nach Upload (Page $pid)");
			return;
		}

		try {
			$page->of(false);
			$img = $page->mm_bild->first();
			if(!$img instanceof Pageimage) {
				$this->api->log->save('medienmanager', 'createVariants: kein Pageimage, Klasse=' . ($img ? get_class($img) : 'null'));
				return;
			}
			if(strtolower((string) $img->ext) === 'svg') return;
			if(!is_file($img->filename())) {
				$this->api->log->error('MM Variants: Original fehlt: ' . $img->filename());
				return;
			}

			$thumb = $img->size(MediaManagerAPI::SLOT_GRID_W, MediaManagerAPI::SLOT_GRID_H, $this->thumbnailSizeOptions());
			if($img->error) {
				$this->api->log->error('MM Variants: ' . $img->error);
				return;
			}
			if(!$thumb->filename || !is_file($thumb->filename())) {
				$this->api->log->error('MM Variants: Variationsdatei fehlt (Page ' . $pid . ')');
			}
		} catch(\Throwable $e) {
			$this->api->log->error('MM Variants: ' . $e->getMessage());
		}
	}

	/**
	 * Optionen für Vorschau-Größen (Grid, Picker, Upload-Nachgenerierung).
	 * cropping: false = gesamtes Bild in die Box skaliert (ggf. Letterboxing), kein harter Zuschnitt.
	 */
	public function thumbnailSizeOptions(): array {
		return ['cropping' => false];
	}

	/**
	 * Dreht die Original-Datei eines Pageimage per ImageSizer (In-Place), löscht veraltete Variationen.
	 *
	 * Hinweis: ProcessWire hat kein Pageimage::rotate(); Rotation erfolgt über ImageSizerEngine auf der Quelldatei.
	 *
	 * @return bool True bei Erfolg
	 */
	public function rotateMasterPageimage(Pageimage $bild, int $degrees): bool {
		if(!in_array(abs($degrees), [90, 180, 270], true)) return false;
		$filename = $bild->filename();
		if(!is_file($filename) || !is_readable($filename)) return false;

		$sizer = new ImageSizer($filename, []);
		$this->api->wire($sizer);
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
	public function resizeMasterPageimage(Pageimage $bild, int $width, int $height, bool $cropping = false): bool {
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
		$this->api->wire($sizer);
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
	public function ensureWebpForPageimage(Pageimage $img): bool {
		if(strtolower((string) $img->ext) === 'svg') return false;
		$webp = $img->webp();
		if($webp->exists()) return true;
		return (bool) $webp->create();
	}

	/** Vorschau-URL (Pageimage-Variation) mit festen Maßen. */
	public function getThumbnailUrl(Page $item, int $width = MediaManagerAPI::SLOT_GRID_W, int $height = MediaManagerAPI::SLOT_GRID_H): string {
		$img = $this->api->getPrimaryPageimage($item);
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
		$w    = MediaManagerAPI::SLOT_GRID_W;
		$h    = MediaManagerAPI::SLOT_GRID_H;
		if($slot === 'picker') {
			$w = MediaManagerAPI::SLOT_PICKER_W;
			$h = MediaManagerAPI::SLOT_PICKER_H;
		} elseif($slot === 'edit') {
			$w = MediaManagerAPI::SLOT_EDIT_W;
			$h = MediaManagerAPI::SLOT_EDIT_H;
		} elseif($slot === 'chip') {
			$w = MediaManagerAPI::SLOT_CHIP_W;
			$h = MediaManagerAPI::SLOT_CHIP_H;
		}
		return $this->getThumbnailUrl($item, $w, $h);
	}
	public function getMediaFocus(Page $item): array {
		$img = $this->api->getPrimaryPageimage($item);
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
	public function setMediaFocus(Page $item, float $top, float $left): bool {
		if(!$item->id) return false;
		$item->of(false);
		$img = $this->api->getPrimaryPageimage($item);
		if(!$img instanceof Pageimage) return false;

		$top  = max(0.0, min(100.0, round($top, 1)));
		$left = max(0.0, min(100.0, round($left, 1)));

		$img->focus($top, $left);
		$item->save();
		$img->removeVariations();
		return true;
	}
}
