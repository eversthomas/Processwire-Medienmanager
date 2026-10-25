<?php namespace ProcessWire;

require_once __DIR__ . '/lib/services/MediaManagerService.php';
require_once __DIR__ . '/lib/services/MediaSvgSanitizer.php';
require_once __DIR__ . '/lib/services/MediaUsageService.php';
require_once __DIR__ . '/lib/services/MediaInstallService.php';
require_once __DIR__ . '/lib/services/MediaFileService.php';
require_once __DIR__ . '/lib/services/MediaImageService.php';
require_once __DIR__ . '/lib/services/MediaRenderService.php';
require_once __DIR__ . '/lib/services/MediaItemService.php';

/**
 * Zentrale Domänen-API für Medien-Items (Fassade).
 *
 * Öffentliche Methoden und Hook-Namen bleiben stabil; die Implementierung liegt in
 * Services unter {@see lib/services/}.
 */
class MediaManagerAPI extends Wire {

	const ROOT_TEMPLATE      = 'medienmanager-root';
	const ITEM_TEMPLATE      = 'medienmanager-item';
	const KATEGORIE_TEMPLATE = 'medienmanager-kategorie';
	const ROOT_NAME          = 'medienmanager';

	public const THUMB_WIDTH  = 300;
	public const THUMB_HEIGHT = 300;

	public const SLOT_GRID_W = 300;
	public const SLOT_GRID_H = 300;
	public const SLOT_PICKER_W = 160;
	public const SLOT_PICKER_H = 120;
	public const SLOT_EDIT_W = 300;
	public const SLOT_EDIT_H = 225;
	public const SLOT_CHIP_W = 120;
	public const SLOT_CHIP_H = 90;

	protected MediaItemService $items;
	protected MediaFileService $files;
	protected MediaImageService $images;
	protected MediaUsageService $usage;
	protected MediaRenderService $render;
	protected MediaInstallService $install;
	protected MediaSvgSanitizer $svg;

	public function __construct(?ProcessWire $wire = null) {
		parent::__construct();
		if($wire) {
			$this->wire($wire);
		}
		$this->items   = new MediaItemService($this);
		$this->files   = new MediaFileService($this);
		$this->images  = new MediaImageService($this);
		$this->usage   = new MediaUsageService($this);
		$this->render  = new MediaRenderService($this);
		$this->install = new MediaInstallService($this);
		$this->svg     = new MediaSvgSanitizer($this);
	}

	/** @internal Service-Zugriff für Tests / Erweiterungen */
	public function items(): MediaItemService { return $this->items; }
	public function files(): MediaFileService { return $this->files; }
	public function images(): MediaImageService { return $this->images; }
	public function usage(): MediaUsageService { return $this->usage; }
	public function renderService(): MediaRenderService { return $this->render; }
	public function installService(): MediaInstallService { return $this->install; }
	public function svg(): MediaSvgSanitizer { return $this->svg; }

	// -----------------------------------------------------------------------
	// Root / Installation
	// -----------------------------------------------------------------------

	/** @internal von Services / Install */
	public function _getRootPage(): Page {
		return $this->install->_getRootPage();
	}

	public function getRootPageId(): int {
		return $this->install->getRootPageId();
	}

	public function ___install(): void {
		$this->install->install();
	}

	public function ensureSvgExtension(): void {
		$this->install->ensureSvgExtension();
	}

	public function cleanupPhantomRootPage(): bool {
		return $this->install->cleanupPhantomRootPage();
	}

	public function ___uninstall(): void {
		$this->install->uninstall();
	}

	// -----------------------------------------------------------------------
	// Items / Kategorien
	// -----------------------------------------------------------------------

	/** @param array{typ?: string, typs?: list<string>, kategorie_id?: int, q?: string} $filters */
	public function ___findMedia(array $filters = [], int $start = 0, int $limit = 24): PageArray {
		return $this->items->findMedia($filters, $start, $limit);
	}

	public function ___getMediaItem(int $id): Page {
		return $this->items->getMediaItem($id);
	}

	public function ___getKategorien(int $parentId = 0): PageArray {
		return $this->items->getKategorien($parentId);
	}

	/** @param array{titel?: string, typ?: string, beschreibung?: string, tags?: string, kategorie_id?: int} $data */
	public function ___createMediaItem(array $data, string $uploadedFile = '', string $originalName = ''): Page {
		return $this->items->createMediaItem($data, $uploadedFile, $originalName);
	}

	/** @param array<string, mixed> $data */
	public function ___saveMediaItem(int $id, array $data): Page {
		return $this->items->saveMediaItem($id, $data);
	}

	public function ___duplicateMediaItem(int $id): Page {
		return $this->items->duplicateMediaItem($id);
	}

	public function ___deleteMedia(int $id): bool {
		return $this->items->deleteMedia($id);
	}

	public function ___createKategorie(string $titel, int $parentId = 0): Page {
		return $this->items->createKategorie($titel, $parentId);
	}

	public function ___deleteKategorie(int $id): bool {
		return $this->items->deleteKategorie($id);
	}

	// -----------------------------------------------------------------------
	// Dateien / Metadaten
	// -----------------------------------------------------------------------

	public function getTypString(Page $item): string {
		return $this->files->getTypString($item);
	}

	public function getAccessibleLabel(Page $item): string {
		return $this->files->getAccessibleLabel($item);
	}

	public function getCaption(Page $item): string {
		return $this->files->getCaption($item);
	}

	public function hasRenderableImage(Page $item): bool {
		return $this->files->hasRenderableImage($item);
	}

	public function getMediaTypeIconHtml(string $typ, string $spanClass = 'mm-grid-icon'): string {
		return $this->files->getMediaTypeIconHtml($typ, $spanClass);
	}

	public function getPublicFileUrl(Page $item): string {
		return $this->files->getPublicFileUrl($item);
	}

	public function ___replacePrimaryFile(Page $item, string $tmpPath, string $originalName): bool {
		return $this->files->replacePrimaryFile($item, $tmpPath, $originalName);
	}

	/** @internal von FileService / Cross-Calls */
	public function fileExtensionMatches(string $newExt, string $oldExt): bool {
		return $this->files->fileExtensionMatches($newExt, $oldExt);
	}

	public function getPrimaryPageimage(Page $item): ?Pageimage {
		return $this->files->getPrimaryPageimage($item);
	}

	public function getPrimaryNonImageFile(Page $item): ?Pagefile {
		return $this->files->getPrimaryNonImageFile($item);
	}

	public function getPrimaryPagefileForDisplay(Page $item): ?Pagefile {
		return $this->files->getPrimaryPagefileForDisplay($item);
	}

	public function getPrimaryBasename(Page $item): string {
		return $this->files->getPrimaryBasename($item);
	}

	public function getPrimaryFilesizeStr(Page $item): string {
		return $this->files->getPrimaryFilesizeStr($item);
	}

	public function getPrimaryDimensionsStr(Page $item): string {
		return $this->files->getPrimaryDimensionsStr($item);
	}

	// -----------------------------------------------------------------------
	// Bilder
	// -----------------------------------------------------------------------

	/** @internal von Item/File-Service nach Upload */
	public function ensureWebpAfterVariants(Page $p): void {
		$this->images->ensureWebpAfterVariants($p);
	}

	public function ___createVariants(Page $p): void {
		$this->images->createVariants($p);
	}

	/** @internal */
	public function thumbnailSizeOptions(): array {
		return $this->images->thumbnailSizeOptions();
	}

	public function ___rotateMasterPageimage(Pageimage $bild, int $degrees): bool {
		return $this->images->rotateMasterPageimage($bild, $degrees);
	}

	public function ___resizeMasterPageimage(Pageimage $bild, int $width, int $height, bool $cropping = false): bool {
		return $this->images->resizeMasterPageimage($bild, $width, $height, $cropping);
	}

	public function ___ensureWebpForPageimage(Pageimage $img): bool {
		return $this->images->ensureWebpForPageimage($img);
	}

	public function getThumbnailUrl(Page $item, int $width = self::SLOT_GRID_W, int $height = self::SLOT_GRID_H): string {
		return $this->images->getThumbnailUrl($item, $width, $height);
	}

	public function getThumbnailUrlForSlot(Page $item, string $slot): string {
		return $this->images->getThumbnailUrlForSlot($item, $slot);
	}

	public function getMediaFocus(Page $item): array {
		return $this->images->getMediaFocus($item);
	}

	public function ___setMediaFocus(Page $item, float $top, float $left): bool {
		return $this->images->setMediaFocus($item, $top, $left);
	}

	// -----------------------------------------------------------------------
	// Usage
	// -----------------------------------------------------------------------

	/** @internal */
	public function getMediaReferenceFields(): array {
		return $this->usage->getMediaReferenceFields();
	}

	public function ___getReferencingPages($item): PageArray {
		return $this->usage->getReferencingPages($item);
	}

	/** @return int[] */
	public function ___getAllUsedMediaIds(): array {
		return $this->usage->getAllUsedMediaIds();
	}

	/** @return array<int, int> */
	public function ___getAllMediaUsageCounts(): array {
		return $this->usage->getAllMediaUsageCounts();
	}

	public function isMediaInUse($item): bool {
		return $this->usage->isMediaInUse($item);
	}

	// -----------------------------------------------------------------------
	// SVG
	// -----------------------------------------------------------------------

	public function sanitizeSvgFile(string $filePath): bool {
		return $this->svg->sanitizeSvgFile($filePath);
	}

	// -----------------------------------------------------------------------
	// Frontend-Render
	// -----------------------------------------------------------------------

	public function isMediaItem(?Page $page): bool {
		return $this->render->isMediaItem($page);
	}

	public function ___renderMedia(Page $media, array $options = []): string {
		return $this->render->renderMedia($media, $options);
	}

	public function ___renderPicture(Pageimage $img, array $options = [], ?Page $media = null): string {
		return $this->render->renderPicture($img, $options, $media);
	}

	public function ___renderVideo(Page $media, array $options = []): string {
		return $this->render->renderVideo($media, $options);
	}

	public function ___renderPdf(Page $media, array $options = []): string {
		return $this->render->renderPdf($media, $options);
	}

	public function ___renderMediaCollection(PageArray $items, array $options = []): string {
		return $this->render->renderMediaCollection($items, $options);
	}

	// -----------------------------------------------------------------------
	// Frontend-Hooks
	// -----------------------------------------------------------------------

	protected static function apiForHooks(Wire $wire): self {
		static $instance = null;
		if($instance === null) {
			$instance = new self($wire);
		}
		return $instance;
	}

	/**
	 * Registriert alle Frontend-Hooks für $media->render(), Properties und PageArray.
	 */
	public static function registerFrontendHooks(Wire $wire): void {
		static $registered = false;
		if($registered) return;
		$registered = true;

		$api = self::apiForHooks($wire);

		$wire->addHookMethod('Page::renderMedia', function(HookEvent $event) use ($api) {
			$page = $event->object;
			if(!$page instanceof Page) return;
			if(!$api->isMediaItem($page)) return;
			$options = $event->arguments(0);
			if(!is_array($options)) $options = [];
			$event->return = $api->renderMedia($page, $options);
		});

		$wire->addHookBefore('Page::render', function(HookEvent $event) use ($api) {
			$page = $event->object;
			if(!$page instanceof Page) return;
			if($page->template && $page->template->name === self::ITEM_TEMPLATE) {
				$options = $event->arguments(0);
				if(!is_array($options)) $options = [];
				$event->replace = true;
				$event->return = $api->renderMedia($page, $options);
			}
		});

		try {
			$wire->addHookMethod('PageArray::renderMedia', function(HookEvent $event) use ($api) {
				$pageArray = $event->object;
				if(!$pageArray instanceof PageArray || !$pageArray->count()) return;
				$options = $event->arguments(0);
				if(!is_array($options)) $options = [];
				$event->return = $api->renderMediaCollection($pageArray, $options);
			});
		} catch(\Throwable $e) {}

		try {
			$wire->addHookBefore('PageArray::render', function(HookEvent $event) use ($api) {
				$pageArray = $event->object;
				if(!$pageArray instanceof PageArray || !$pageArray->count()) return;
				$first = $pageArray->first();
				if($first && $first->template && $first->template->name === self::ITEM_TEMPLATE) {
					$options = $event->arguments(0);
					if(!is_array($options)) $options = [];
					$event->replace = true;
					$event->return = $api->renderMediaCollection($pageArray, $options);
				}
			});
		} catch(\Throwable $e) {}

		$wire->addHookMethod('Page::mediaUrl', function(HookEvent $event) use ($api) {
			$page = $event->object;
			if(!$page instanceof Page || $page->template->name !== self::ITEM_TEMPLATE) return;
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

		$wire->addHookProperty('Page::mediaUrl', function(HookEvent $event) use ($api) {
			$page = $event->object;
			if(!$page instanceof Page || $page->template->name !== self::ITEM_TEMPLATE) return;
			$event->return = $api->getPublicFileUrl($page);
		});

		$wire->addHookProperty('Page::fileUrl', function(HookEvent $event) use ($api) {
			$page = $event->object;
			if(!$page instanceof Page || $page->template->name !== self::ITEM_TEMPLATE) return;
			$event->return = $api->getPublicFileUrl($page);
		});

		$wire->addHookProperty('Page::alt', function(HookEvent $event) use ($api) {
			$page = $event->object;
			if(!$page instanceof Page || $page->template->name !== self::ITEM_TEMPLATE) return;
			$event->return = $api->getAccessibleLabel($page);
		});

		$wire->addHookProperty('Page::caption', function(HookEvent $event) use ($api) {
			$page = $event->object;
			if(!$page instanceof Page || $page->template->name !== self::ITEM_TEMPLATE) return;
			$event->return = $api->getCaption($page);
		});

		$wire->addHookProperty('Page::isImage', function(HookEvent $event) use ($api) {
			$page = $event->object;
			if(!$page instanceof Page || $page->template->name !== self::ITEM_TEMPLATE) return;
			$event->return = $api->hasRenderableImage($page);
		});

		$wire->addHookProperty('Page::isVideo', function(HookEvent $event) use ($api) {
			$page = $event->object;
			if(!$page instanceof Page || $page->template->name !== self::ITEM_TEMPLATE) return;
			$event->return = $api->getTypString($page) === 'video';
		});

		$wire->addHookProperty('Page::isPdf', function(HookEvent $event) use ($api) {
			$page = $event->object;
			if(!$page instanceof Page || $page->template->name !== self::ITEM_TEMPLATE) return;
			$event->return = $api->getTypString($page) === 'pdf';
		});

		$wire->addHookProperty('Page::pageimage', function(HookEvent $event) use ($api) {
			$page = $event->object;
			if(!$page instanceof Page || $page->template->name !== self::ITEM_TEMPLATE) return;
			$event->return = $api->getPrimaryPageimage($page);
		});

		$wire->addHookProperty('Page::dimensions', function(HookEvent $event) use ($api) {
			$page = $event->object;
			if(!$page instanceof Page || $page->template->name !== self::ITEM_TEMPLATE) return;
			$event->return = $api->getPrimaryDimensionsStr($page);
		});

		$wire->addHookProperty('Page::filesize', function(HookEvent $event) use ($api) {
			$page = $event->object;
			if(!$page instanceof Page || $page->template->name !== self::ITEM_TEMPLATE) return;
			$event->return = $api->getPrimaryFilesizeStr($page);
		});

		$wire->addHookProperty('Page::focus', function(HookEvent $event) use ($api) {
			$page = $event->object;
			if(!$page instanceof Page || $page->template->name !== self::ITEM_TEMPLATE) return;
			$event->return = $api->getMediaFocus($page);
		});

		$wire->addHookProperty('Page::isSvg', function(HookEvent $event) use ($api) {
			$page = $event->object;
			if(!$page instanceof Page || $page->template->name !== self::ITEM_TEMPLATE) return;
			$img = $api->getPrimaryPageimage($page);
			$event->return = ($img instanceof Pageimage && strtolower((string) $img->ext) === 'svg');
		});

		$wire->addHookProperty('Page::usedOnPages', function(HookEvent $event) use ($api) {
			$page = $event->object;
			if(!$page instanceof Page || $page->template->name !== self::ITEM_TEMPLATE) return;
			$event->return = $api->getReferencingPages($page);
		});

		$wire->addHookProperty('Page::usageCount', function(HookEvent $event) use ($api) {
			$page = $event->object;
			if(!$page instanceof Page || $page->template->name !== self::ITEM_TEMPLATE) return;
			$event->return = $api->getReferencingPages($page)->count();
		});

		$wire->addHookMethod('Page::focalUrl', function(HookEvent $event) use ($api) {
			$page = $event->object;
			if(!$page instanceof Page || $page->template->name !== self::ITEM_TEMPLATE) return;
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
