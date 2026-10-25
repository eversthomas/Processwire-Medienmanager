<?php namespace ProcessWire;

/**
 * SVG-Sanitizer gegen Stored XSS / XXE.
 */
class MediaSvgSanitizer extends MediaManagerService {

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
			$this->api->log->error('MM SVG Sanitizer: XXE Attempt detected in ' . basename($filePath));
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
			$this->api->log->error('MM SVG Sanitizer: Ungültiges XML in ' . basename($filePath));
			return false;
		}

		// Root-Element prüfen
		if(!$dom->documentElement || strtolower($dom->documentElement->nodeName) !== 'svg') {
			$this->api->log->error('MM SVG Sanitizer: Root-Element ist nicht <svg> in ' . basename($filePath));
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
}
