<?php namespace ProcessWire;

/**
 * Installation, Root-Page, Deinstallations-Config.
 */
class MediaInstallService extends MediaManagerService {

	public function _getRootPage(): Page {
		// 1. Primär: Die tatsächliche Process-Admin-Page von bsProcessMedienManager
		$processPage = $this->api->pages->get("process=bsProcessMedienManager");
		if($processPage->id) return $processPage;

		// 2. Unter Setup suchen
		$adminId = (int) $this->api->config->adminRootPageID;
		$setup = $this->api->pages->get("name=setup, parent=$adminId");
		if($setup->id) {
			$p = $setup->child("name=" . MediaManagerAPI::ROOT_NAME . ", include=all");
			if($p->id) return $p;
		}

		// 3. Fallback: beliebige Page mit name=medienmanager unter Admin
		return $this->api->pages->get("name=" . MediaManagerAPI::ROOT_NAME . ", parent=$adminId, include=all");
	}

	/** @return int Page-ID des Medien-Manager-Roots (Konfiguration oder ermittelt) */
	public function getRootPageId(): int {
		$config = $this->api->modules->getModuleConfigData('bsProcessMedienManager');
		$id = isset($config['rootPageID']) ? (int) $config['rootPageID'] : 0;
		$realPage = $this->_getRootPage();

		// Falls rootPageID auf eine veraltete/verwaiste Page ohne Prozess zeigt, auf echte Process-Page korrigieren
		if($id > 0 && $realPage->id && $id !== $realPage->id) {
			$p = $this->api->pages->get("id=$id, include=all");
			if($p->id && (!$p->process || (string)$p->process !== 'bsProcessMedienManager')) {
				$id = (int) $realPage->id;
				$config['rootPageID'] = $id;
				$this->api->modules->saveModuleConfigData('bsProcessMedienManager', $config);
			}
		}

		if($id <= 0 && $realPage->id) {
			$id = (int) $realPage->id;
			$config['rootPageID'] = $id;
			$this->api->modules->saveModuleConfigData('bsProcessMedienManager', $config);
		}

		return (int) $id;
	}
	public function install(): void {
		$this->_installFelder();
		$this->_installFieldgroups();
		$this->_installTemplates();
		$this->_installKategorieTemplate();
		$this->_installRootPage();
	}

	public function _installFelder(): void {
		$fields = $this->api->fields;
		$pw = $this->api;
		
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
		$f = $this->api->fields->get('mm_bild');
		if($f && strpos((string) $f->extensions, 'svg') === false) {
			$f->extensions = trim($f->extensions . ' svg');
			$f->save();
		}
	}

	public function _installFieldgroups(): void {
		$fg = $this->api->fieldgroups->get(MediaManagerAPI::ITEM_TEMPLATE) ?: new Fieldgroup();
		$fg->name = MediaManagerAPI::ITEM_TEMPLATE;
		foreach(['title', 'mm_bild', 'mm_datei', 'mm_titel', 'mm_alt', 'mm_caption', 'mm_beschreibung', 'mm_tags', 'mm_typ', 'mm_kategorie'] as $fn) {
			if($this->api->fields->get($fn)) $fg->add($fn);
		}
		$fg->save();
	}

	public function _installTemplates(): void {
		$t = $this->api->templates->get(MediaManagerAPI::ITEM_TEMPLATE) ?: new Template();
		$t->name = MediaManagerAPI::ITEM_TEMPLATE;
		$t->fieldgroup = $this->api->fieldgroups->get(MediaManagerAPI::ITEM_TEMPLATE);
		$t->save();
	}

	/**
	 * Template für Kategorie-Pages (Kinder der Medien-Root-Page).
	 */
	public function _installKategorieTemplate(): void {
		$fg = $this->api->fieldgroups->get(MediaManagerAPI::KATEGORIE_TEMPLATE) ?: new Fieldgroup();
		$fg->name = MediaManagerAPI::KATEGORIE_TEMPLATE;
		if($this->api->fields->get('title')) {
			if(!$fg->hasField('title')) $fg->add('title');
		}
		$fg->save();

		$t = $this->api->templates->get(MediaManagerAPI::KATEGORIE_TEMPLATE) ?: new Template();
		$t->name = MediaManagerAPI::KATEGORIE_TEMPLATE;
		$t->fieldgroup = $this->api->fieldgroups->get(MediaManagerAPI::KATEGORIE_TEMPLATE);
		$t->save();
	}

	public function _installRootPage(): Page {
		$root = $this->_getRootPage();
		if(!$root->id) {
			$adminId = (int) $this->api->config->adminRootPageID;
			$setup = $this->api->pages->get("name=setup, parent=$adminId");
			$parent = $setup->id ? $setup : $this->api->pages->get($adminId);
			$root = $parent->child("name=" . MediaManagerAPI::ROOT_NAME . ", include=all");
			if(!$root->id) {
				$root = $this->api->pages->newPage([
					'template' => 'admin',
					'parent'   => $parent,
					'name'     => MediaManagerAPI::ROOT_NAME,
					'title'    => 'Medien Manager',
					'process'  => 'bsProcessMedienManager',
				]);
				$root->save();
			}
		}
		$cfg = $this->api->modules->getModuleConfigData('bsProcessMedienManager');
		if($root->id && ($cfg['rootPageID'] ?? 0) !== (int) $root->id) {
			$cfg['rootPageID'] = (int) $root->id;
			$this->api->modules->saveModuleConfigData('bsProcessMedienManager', $cfg);
		}
		return $root;
	}

	/**
	 * Bereinigt die verwaiste Hauptmenü-Seite (/admin/medienmanager/), falls vorhanden.
	 * Hängt darin enthaltene Items/Kategorien sicher auf die echte Process-Page um.
	 *
	 * @return bool True, wenn eine verwaiste Seite bereinigt wurde
	 */
	public function cleanupPhantomRootPage(): bool {
		$adminId = (int) $this->api->config->adminRootPageID;
		$realPage = $this->_getRootPage();
		if(!$realPage->id) return false;

		// Verwaiste Hauptmenü-Seite suchen: direkt unter /admin/ (parent=$adminId), ungleich der echten Process-Page
		$phantom = $this->api->pages->get("name=" . MediaManagerAPI::ROOT_NAME . ", parent=$adminId, id!={$realPage->id}, include=all");
		if($phantom->id && (!$phantom->process || (string)$phantom->process !== 'bsProcessMedienManager')) {
			// Falls Items oder Kategorien darunter liegen, auf die echte Process-Page umhängen
			$children = $phantom->children("include=all");
			if($children->count()) {
				foreach($children as $child) {
					$child->parent = $realPage;
					$child->save();
				}
			}

			$phantomId = $phantom->id;
			$this->api->pages->delete($phantom, true);

			// rootPageID sicherstellen
			$cfg = $this->api->modules->getModuleConfigData('bsProcessMedienManager');
			$cfg['rootPageID'] = (int) $realPage->id;
			$this->api->modules->saveModuleConfigData('bsProcessMedienManager', $cfg);

			$this->api->log->save('medienmanager', "Bereinigung: Verwaiste Hauptmenü-Seite ID $phantomId gelöscht, Items auf ID {$realPage->id} umgehängt.");
			return true;
		}

		return false;
	}
	public function uninstall(): void {
		$this->api->modules->saveModuleConfigData('bsProcessMedienManager', []);
	}
}
