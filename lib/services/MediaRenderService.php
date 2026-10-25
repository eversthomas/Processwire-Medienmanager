<?php namespace ProcessWire;

/**
 * Frontend-Ausgabe: picture, video, pdf, Collections.
 */
class MediaRenderService extends MediaManagerService {

	public function isMediaItem(?Page $page): bool {
		return $page instanceof Page && $page->id && $page->template && $page->template->name === MediaManagerAPI::ITEM_TEMPLATE;
	}

	/**
	 * Zentraler Einstiegspunkt für die Ausgabe eines Mediums im Frontend.
	 * Unterstützt Bilder (responsive <picture> mit WebP), Videos (<video>) und PDFs (Download-Link/Embed).
	 *
	 * @param Page $media Die medienmanager-item Page
	 * @param array<string, mixed> $options Ausgabe-Optionen
	 * @return string HTML
	 */
	public function renderMedia(Page $media, array $options = []): string {
		if(!$this->isMediaItem($media)) return '';

		$typ = $this->api->getTypString($media);

		if($this->api->hasRenderableImage($media)) {
			$img = $this->api->getPrimaryPageimage($media);
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
		$pubUrl = $this->api->getPublicFileUrl($media);
		if($pubUrl === '') return '';
		$label = $this->api->getAccessibleLabel($media);
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
	public function renderPicture(Pageimage $img, array $options = [], ?Page $media = null): string {
		$sanitizer = $this->api->sanitizer;

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
			$alt = $this->api->getAccessibleLabel($media);
		} else {
			$alt = (string) $img->description;
		}
		$altEsc = htmlspecialchars($alt, ENT_QUOTES | ENT_HTML5, 'UTF-8');

		// Caption ermitteln
		$captionText = '';
		if(isset($options['caption']) && is_string($options['caption'])) {
			$captionText = $options['caption'];
		} elseif($media && (!isset($options['caption']) || $options['caption'] === true)) {
			$captionText = $this->api->getCaption($media);
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
					if($this->api->ensureWebpForPageimage($thumb)) {
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
	public function renderVideo(Page $media, array $options = []): string {
		$url = $this->api->getPublicFileUrl($media);
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
		} elseif($this->api->hasRenderableImage($media)) {
			$img = $this->api->getPrimaryPageimage($media);
			if($img) $posterAttr = ' poster="' . htmlspecialchars($img->url, ENT_QUOTES, 'UTF-8') . '"';
		}

		$widthAttr  = isset($options['width']) ? ' width="' . (int) $options['width'] . '"' : '';
		$heightAttr = isset($options['height']) ? ' height="' . (int) $options['height'] . '"' : '';

		$videoHtml = "<video src=\"{$urlEsc}\"{$controls}{$autoplay}{$loop}{$muted}{$preload}{$posterAttr}{$widthAttr}{$heightAttr}{$class}></video>";

		$captionText = '';
		if(isset($options['caption']) && is_string($options['caption'])) {
			$captionText = $options['caption'];
		} elseif(!isset($options['caption']) || $options['caption'] === true) {
			$captionText = $this->api->getCaption($media);
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
	public function renderPdf(Page $media, array $options = []): string {
		$url = $this->api->getPublicFileUrl($media);
		if($url === '') return '';

		$urlEsc = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
		$class  = isset($options['class']) ? ' class="' . htmlspecialchars((string) $options['class'], ENT_QUOTES, 'UTF-8') . '"' : '';

		if(!empty($options['embed'])) {
			$w = isset($options['width']) ? ' width="' . (int) $options['width'] . '"' : ' width="100%"';
			$h = isset($options['height']) ? ' height="' . (int) $options['height'] . '"' : ' height="600"';
			return "<iframe src=\"{$urlEsc}\"{$w}{$h}{$class} frameborder=\"0\"></iframe>";
		}

		$label = $options['label'] ?? $this->api->getAccessibleLabel($media);
		$labelEsc = htmlspecialchars($label, ENT_QUOTES | ENT_HTML5, 'UTF-8');

		$sizeInfo = '';
		if($options['showSize'] ?? true) {
			$sz = $this->api->getPrimaryFilesizeStr($media);
			if($sz !== '') $sizeInfo = ' <span class="mm-pdf-size">(' . htmlspecialchars($sz, ENT_QUOTES, 'UTF-8') . ')</span>';
		}

		return "<a href=\"{$urlEsc}\" target=\"_blank\" rel=\"noopener\"{$class}>{$labelEsc}{$sizeInfo}</a>";
	}

	/**
	 * Rendert eine PageArray-Sammlung von Medien-Items.
	 */
	public function renderMediaCollection(PageArray $items, array $options = []): string {
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
}
