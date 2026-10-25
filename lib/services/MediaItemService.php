<?php namespace ProcessWire;

/**
 * Suche, CRUD für Medien-Items und Kategorien.
 */
class MediaItemService extends MediaManagerService {

	public function findMedia(array $filters = [], int $start = 0, int $limit = 24): PageArray {
		$sanitizer = $this->api->sanitizer;
		$selector = "template=" . MediaManagerAPI::ITEM_TEMPLATE . ", include=hidden, sort=-created";

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
			if($this->api->fields->get('mm_tags')) {
				$suchFelder[] = 'mm_tags';
			}
			if($this->api->fields->get('mm_beschreibung')) {
				$suchFelder[] = 'mm_beschreibung';
			}
			if($this->api->fields->get('mm_alt')) {
				$suchFelder[] = 'mm_alt';
			}
			if($this->api->fields->get('mm_caption')) {
				$suchFelder[] = 'mm_caption';
			}
			$selector .= ', ' . implode('|', $suchFelder) . '%=' . $q;
		}

		if(!empty($filters['usage'])) {
			$usage = strtolower((string) $filters['usage']);
			if($usage === 'unused') {
				$usedIds = $this->api->getAllUsedMediaIds();
				if(!empty($usedIds)) {
					$selector .= ', id!=' . implode('|', $usedIds);
				}
			} elseif($usage === 'used') {
				$usedIds = $this->api->getAllUsedMediaIds();
				if(!empty($usedIds)) {
					$selector .= ', id=' . implode('|', $usedIds);
				} else {
					$selector .= ', id=0';
				}
			}
		}

		$selector .= ", start=$start, limit=$limit";
		return $this->api->pages->find($selector);
	}

	/** Einzelnes Medien-Item oder leere Page bei ungültiger ID. */
	public function getMediaItem(int $id): Page {
		return $this->api->pages->get("id=" . (int)$id . ", template=" . MediaManagerAPI::ITEM_TEMPLATE . ", include=all");
	}

	/** Kategorie-Pages unter dem Root (oder unter $parentId). */
	public function getKategorien(int $parentId = 0): PageArray {
		if($parentId <= 0) $parentId = $this->api->getRootPageId();
		return $this->api->pages->find("parent=$parentId, template=" . MediaManagerAPI::KATEGORIE_TEMPLATE . ", include=hidden, sort=title");
	}

	/**
	 * Neues Medien-Item inkl. optionaler Datei (Bild → mm_bild, sonst mm_datei).
	 *
	 * @param array{titel?: string, typ?: string, beschreibung?: string, tags?: string, kategorie_id?: int} $data
	 */
	public function createMediaItem(array $data, string $uploadedFile = '', string $originalName = ''): Page {
		$sanitizer = $this->api->sanitizer;
		$rootId = $this->api->getRootPageId();
		if($rootId <= 0) return $this->api->pages->newNullPage();

		$p = $this->api->pages->newPage(['template' => MediaManagerAPI::ITEM_TEMPLATE, 'parent' => $rootId]);
		$p->title = $sanitizer->text($data['titel'] ?? 'Unbenannt');
		$p->mm_titel = $p->title;

		$typMap = ['bild' => 1, 'video' => 2, 'pdf' => 3];
		$p->mm_typ = $typMap[$data['typ'] ?? 'bild'] ?? 1;

		if($p->hasField('mm_beschreibung') && array_key_exists('beschreibung', $data)) {
			$p->mm_beschreibung = $this->api->sanitizer->textarea((string) $data['beschreibung']);
		}
		if($p->hasField('mm_tags') && array_key_exists('tags', $data)) {
			$p->mm_tags = $sanitizer->text((string) $data['tags']);
		}

		if(!empty($data['kategorie_id'])) {
			$kat = $this->api->pages->get((int) $data['kategorie_id']);
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

			if($ext === 'svg' && !$this->api->sanitizeSvgFile($uploadedFile)) {
				$this->api->log->error("MM Upload: SVG-Sanitization fehlgeschlagen für $originalName");
				$this->api->pages->delete($p, true);
				return $this->api->pages->newNullPage();
			}

			if($isImage && $p->hasField('mm_bild')) {
				$p->mm_bild->add($uploadedFile);
				if($safeBasename !== '') $p->mm_bild->last()->rename($safeBasename);
				$p->save();
				if($ext !== 'svg') {
					$this->api->createVariants($p);
					$this->api->ensureWebpAfterVariants($p);
				}
			} else {
				$p->mm_datei->add($uploadedFile);
				if($safeBasename !== '') $p->mm_datei->last()->rename($safeBasename);
				$p->save();
			}
		}
		return $p;
	}
	public function saveMediaItem(int $id, array $data): Page {
		$p = $this->getMediaItem($id);
		if(!$p->id) return $p;
		$p->of(false);
		$sanitizer = $this->api->sanitizer;
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
	public function duplicateMediaItem(int $id): Page {
		$p = $this->getMediaItem($id);
		if(!$p->id) return $this->api->pages->newNullPage();
		$parent = $this->api->pages->get($this->api->getRootPageId());
		if(!$parent->id) return $this->api->pages->newNullPage();
		$copy = $this->api->pages->clone($p, $parent, false);
		if(!$copy->id) return $this->api->pages->newNullPage();
		$copy->of(false);
		$base = (string) ($p->mm_titel ?: $p->title);
		$copy->mm_titel = $base . ' (Kopie)';
		$copy->title = $copy->mm_titel;
		$copy->save();
		return $copy;
	}
	public function deleteMedia(int $id): bool {
		$p = $this->getMediaItem($id);
		if($p->id) return $this->api->pages->delete($p, true);
		return false;
	}
	public function createKategorie(string $titel, int $parentId = 0): Page {
		$sanitizer = $this->api->sanitizer;
		$titel     = $sanitizer->text($titel);
		if($titel === '') return $this->api->pages->newNullPage();

		if($parentId <= 0) $parentId = $this->api->getRootPageId();
		$parent = $this->api->pages->get((int) $parentId);
		if(!$parent->id) return $this->api->pages->newNullPage();

		$baseName = $sanitizer->pageName($titel);
		if($baseName === '') $baseName = 'kategorie';
		$name = $this->api->pages->names()->uniquePageName($baseName, null, ['parent' => $parent]);

		$p = $this->api->pages->newPage([
			'template' => MediaManagerAPI::KATEGORIE_TEMPLATE,
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
	public function deleteKategorie(int $id): bool {
		$p = $this->api->pages->get((int) $id);
		if(!$p->id || $p->template->name !== MediaManagerAPI::KATEGORIE_TEMPLATE) return false;

		$n = $this->api->pages->count('template=' . MediaManagerAPI::ITEM_TEMPLATE . ', mm_kategorie=' . (int) $id . ', include=all');
		if($n > 0) return false;

		$this->api->pages->delete($p, true);
		return true;
	}
}
