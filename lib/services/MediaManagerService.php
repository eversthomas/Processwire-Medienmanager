<?php namespace ProcessWire;

/**
 * Basis für Domänen-Services des Medien Managers.
 * ProcessWire-API und Nachbar-Services laufen über die Fassade {@see MediaManagerAPI}.
 */
abstract class MediaManagerService {

	protected MediaManagerAPI $api;

	public function __construct(MediaManagerAPI $api) {
		$this->api = $api;
	}
}
