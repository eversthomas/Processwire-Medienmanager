<?php namespace ProcessWire;

/**
 * Verwendungsnachweis und used/unused-Filter.
 */
class MediaUsageService extends MediaManagerService {

	public function getMediaReferenceFields(): array {
		$out = [];
		foreach($this->api->fields->find('type=FieldtypeMedienManager') as $field) {
			$out[] = $field;
		}

		$tpl = $this->api->templates->get(MediaManagerAPI::ITEM_TEMPLATE);
		if($tpl && $tpl->id) {
			$tplId = (int) $tpl->id;
			foreach($this->api->fields->find('type=FieldtypePage') as $field) {
				$single = (int) $field->get('template_id') === $tplId;
				$multi  = is_array($field->get('template_ids')) && in_array($tplId, $field->get('template_ids'), true);
				if($single || $multi) {
					$out[] = $field;
				}
			}
		}

		return $out;
	}

	public function getReferencingPages($item): PageArray {
		$id = $item instanceof Page ? (int) $item->id : (int) $item;
		$out = $this->api->pages->newPageArray();
		if($id <= 0) return $out;

		$pageIds = [];
		foreach($this->getMediaReferenceFields() as $field) {
			$table = $this->api->database->escapeTable("field_{$field->name}");
			try {
				$stmt = $this->api->database->prepare("SELECT DISTINCT pages_id FROM `{$table}` WHERE data = :id");
				$stmt->execute([':id' => $id]);
				while($row = $stmt->fetch(\PDO::FETCH_NUM)) {
					$pageIds[] = (int) $row[0];
				}
			} catch(\Throwable $e) {}
		}

		$pageIds = array_unique(array_filter($pageIds, fn($pid) => $pid > 0));
		if(!empty($pageIds)) {
			// Nur aktive Seiten laden (nicht im Papierkorb)
			$out = $this->api->pages->find("id=" . implode('|', $pageIds) . ", status<" . Page::statusTrash . ", include=all");
		}

		return $out;
	}

	/**
	 * Liefert alle eindeutigen Medien-IDs zurück, die aktuell auf mindestens einer aktiven Seite referenziert sind.
	 * Quelle: FieldtypeMedienManager und passende FieldtypePage-Felder (wie {@see getReferencingPages()}).
	 *
	 * @return int[]
	 */
	public function getAllUsedMediaIds(): array {
		$mediaIds = [];
		foreach($this->getMediaReferenceFields() as $field) {
			$table = $this->api->database->escapeTable("field_{$field->name}");
			try {
				$sql = "SELECT DISTINCT f.data FROM `{$table}` AS f 
				        JOIN pages AS p ON p.id = f.pages_id 
				        WHERE (p.status & " . Page::statusTrash . ") = 0";
				$stmt = $this->api->database->query($sql);
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
	 * Quelle: FieldtypeMedienManager und passende FieldtypePage-Felder (wie {@see getReferencingPages()}).
	 *
	 * @return array<int, int>
	 */
	public function getAllMediaUsageCounts(): array {
		$counts = [];
		foreach($this->getMediaReferenceFields() as $field) {
			$table = $this->api->database->escapeTable("field_{$field->name}");
			try {
				$sql = "SELECT f.data, COUNT(DISTINCT f.pages_id) AS cnt FROM `{$table}` AS f 
				        JOIN pages AS p ON p.id = f.pages_id 
				        WHERE (p.status & " . Page::statusTrash . ") = 0 
				        GROUP BY f.data";
				$stmt = $this->api->database->query($sql);
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
}
