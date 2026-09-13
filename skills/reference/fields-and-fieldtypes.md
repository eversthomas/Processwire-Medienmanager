# Fields and Fieldtypes

This reference documents ProcessWire's Field/Fieldtype system: how a `Field` stores its
configuration, how the `$fields` API variable manages the collection of fields, how the
abstract `Fieldtype` base class turns raw database rows into PHP values, and the specific
configuration properties exposed by the core Fieldtype modules. All claims below were
verified by reading the actual core source (paths are given per section) in ProcessWire
3.0.257 dev, under `wire/core/` and `wire/modules/Fieldtype/`.

## The Field class

Source: `wire/core/Field.php`

A `Field` object corresponds to one row in the `fields` database table and represents a
single custom field definition (not a value on a page — for the value, see
`$page->get('fieldname')`). Fields are managed by the `$fields` API variable
(`Fields` class, see below).

### Native/permanent settings

`Field` stores a small set of "native" settings directly in a `$settings` array (see
`Field::$settings` and `Field::get()`/`set()`), and everything else as dynamic "data"
properties inherited from `WireData` (i.e. stored in a JSON `data` column in the `fields`
table).

Native settings (from the class doc block and `$settings` array):

- `id` (int) — numeric ID of the field in the database.
- `name` (string) — the field's name.
- `label` (string) — the field's label text. If never set, `$field->label` returns empty
  string from `get()`, but `Field::getLabel()` falls back to `$field->name` when blank
  (see `Field::getText()`).
- `flags` (int) — bitmask of `Field::flag*` constants (see below).
- `type` (Fieldtype|null) — the `Fieldtype` module instance that defines this field's
  type. Setting `$field->type = 'FieldtypeText'` (a string) resolves it via
  `$fieldtypes->get($type)` inside `Field::setFieldtype()`.
- `table` (string, read-only via `getTable()`) — DB table name, `field_` + field name
  (truncated to fit MySQL's 64-char identifier limit), lowercased if
  `$config->dbLowercaseTables` is set.
- `prevTable`, `prevName`, `prevFieldtype` — used internally when a field is renamed or
  has its type changed, so `Fields::save()` can migrate the table/data.
- `flagsStr` (read-only) — space-separated names of the set flags.
- `useRoles` (bool) — convenience getter/setter for the `flagAccess` bit.
- `viewRoles`, `editRoles` (array of role IDs) — populated only when `useRoles` is true.
- `tags` / `tagList` — space-separated tag string / array form, used to group fields in
  the admin (`Field::getTags()`, `setTags()`, `addTag()`, `removeTag()`, `hasTag()`).
- `icon` — icon name shown in the admin (font-awesome name, `fa-` prefix optional).
- `description`, `notes` — additional free-text properties, also stored as dynamic data.

Everything else — `required`, `columnWidth`, `collapsed`, `textFormat`, `showIf`,
`requiredIf`, and any Fieldtype- or Inputfield-specific setting (e.g. `maxFiles`,
`extensions`, `template_id`) — is a **dynamic data property** stored via `WireData`'s
`set()`/`get()` (i.e. the `$this->data` array inherited from `WireData`, JSON-encoded into
the `fields.data` column). These are documented in the class doc block as "Common
Inputfield properties":

```
@property int|bool|null $required        Whether or not this field is required during input
@property string|null   $requiredIf      Selector-style string; conditions under which input is required
@property string|null   $showIf          Selector-style string; conditions under which the Inputfield is shown
@property int|null      $columnWidth     Inputfield column width (percent) 10-100
@property int|null      $collapsed       Inputfield 'collapsed' value (see Inputfield::collapsed* constants)
@property int|null      $textFormat      Inputfield 'textFormat' value
```

### Flag constants

From `Field.php` (bitmask, combine with `|`):

| Constant | Value | Meaning |
|---|---|---|
| `Field::flagAutojoin` | 1 | Field is loaded automatically with every page load. |
| `Field::flagGlobal` | 4 | Field is required in every Fieldgroup/Template; `Fields::save()` auto-adds it to all fieldgroups that lack it. |
| `Field::flagSystem` | 8 | System field — cannot be deleted, renamed, or made non-system. |
| `Field::flagPermanent` | 16 | Field cannot be removed from any fieldgroup/template it's attached to. |
| `Field::flagAccess` | 32 | Field has view/edit access control enabled (drives `useRoles`). |
| `Field::flagAccessAPI` | 64 | If access-controlled, values remain API-accessible even when not viewable (otherwise blanked when output formatting is on). |
| `Field::flagAccessEditor` | 128 | Non-editable-but-viewable values still show (locked) in the page editor. |
| `Field::flagUnique` | 256 | Requests/indicates a unique index on the field's `data` column (3.0.150+). |
| `Field::flagFieldgroupContext` | 2048 | Runtime-only flag: this Field instance is a copy in the context of a specific Fieldgroup/Template and is *not* saveable directly (see `Fieldgroup::getFieldContext()`). |
| `Field::flagSystemOverride` | 32768 | Temporary flag to allow removing `flagSystem`/`flagPermanent` in the same `set()` call. |

Check flags with `$field->hasFlag(Field::flagRequired)`-style calls: `$field->hasFlag(Field::flagSystem)`,
add/remove with `$field->addFlag(...)` / `$field->removeFlag(...)`.

### Reading and setting properties

```php
// standard property access (falls through to WireData/dynamic data)
$field = $fields->get('title');
echo $field->name;          // 'title'
echo $field->label;         // configured label, or '' if unset
echo $field->getLabel();    // configured label, or $field->name if unset (language-aware)
echo $field->type;          // Fieldtype object, __toString()'s to class name e.g. "FieldtypeText"
echo $field->type->className(); // 'FieldtypeText' -- the actual Fieldtype module class name
echo get_class($field->type);   // 'ProcessWire\FieldtypeText' (fully namespaced)

// dynamic/custom settings -- both styles work identically since Field::get()/set()
// fall through to WireData for anything not in the native $settings array
$maxFiles = $field->get('maxFiles');
$maxFiles = $field->maxFiles;

$field->set('columnWidth', 50);
$field->columnWidth = 50;
```

`Field::get($key)` (`Field.php` ~line 412) special-cases `id`, `name`, `type`, `flags`,
`label`, `table`, `flagsStr`, `viewRoles`, `editRoles`, `useRoles`, `prevTable`,
`prevName`, `prevFieldtype`, `icon`, `tags`, `tagList` — everything else falls through to
`parent::get($key)` (`WireData`), which reads from the `data` array.

### Required fields

`required` is **not** a native `Field` setting — it's a plain dynamic data property
(documented on the class as `@property int|bool|null $required`) that the field's
Inputfield module also recognizes (Inputfield has its own `required`/`requiredIf`
attributes, and `Field::___getInputfield()` copies matching custom `data` keys onto the
Inputfield instance — see below). So checking "is this field required":

```php
if ($field->required) {
    // required (may be int 1 or bool true depending on how it was saved)
}
$requiredIf = $field->requiredIf; // selector-style conditional-required string, or empty
```

### Getting the Inputfield for a field

`Field::___getInputfield(Page $page, $contextStr = '')` (hookable) asks the field's
Fieldtype for an `Inputfield` instance (`$this->type->getInputfield($page, $this)`), then:

1. Sets standard attributes: `name` (+ optional context string suffix used by
   repeaters), `label`, plus internal references `hasFieldtype`, `hasField`, `hasPage`.
2. Copies every custom `data` key from the Field onto the Inputfield **if the Inputfield
   recognizes that setting** (`$inputfield->has($key)` for a plain Inputfield, or
   `hasSetting()`/`hasAttribute()` for an `InputfieldWrapper`). This is how settings like
   `required`, `columnWidth`, `collapsed`, or a Fieldtype-specific setting like `rows`
   (for a textarea) end up applied to the actual form input.
3. Applies access-control-based locking/hiding if `useRoles` is set and the current page
   isn't editable/viewable by the field.

### Field configuration screens

`Field::___getConfigInputfields()` builds the admin "Details"/"Input" tabs by combining:

- `$field->type->getConfigInputfields($field)` — Fieldtype-specific settings (a
  `Fieldtype`-hookable method, e.g. `extensions`, `maxFiles` for FieldtypeFile).
- `$field->type->getConfigArray($field)` — same idea but as a plain array definition.
- `$field->getInputfield($dummyPage)->getConfigInputfields()` — settings from the
  Inputfield module itself (e.g. `rows`, `size`, `placeholder`).

When a Field is in "fieldgroup context" (`flagFieldgroupContext` set — i.e. it's a
per-template override), only the subset of property names returned by
`$field->type->getConfigAllowContext($field)` (plus `$field->allowContexts`) are shown/
saved, because most Fieldtype settings are meant to be global across all uses of the
field.

### Multi-language label/description/notes

`Field::getText()` (private helper backing `getLabel()`, `getDescription()`,
`getNotes()`) checks `$this->wire()->languages` — if the multi-language `languages` API
var/module is installed, it looks up a per-language dynamic property named
`"{$property}{$language->id}"` (e.g. `label1025`) and falls back to the base `label`
property if the language-specific value is blank. This is why label/description/notes for
non-default languages are stored as separate dynamic data properties named after the
language ID, not as an array. Set language-specific text with
`$field->setLabel('Titel', $germanLanguage)`.

### Field::debugInfoSmall()

A quick sanity/debug snapshot:

```php
$field->debugInfoSmall();
// => ['id' => 1, 'name' => 'title', 'label' => 'Title', 'type' => 'FieldtypeText']
```

## The $fields API variable (Fields class)

Source: `wire/core/Fields.php` — `class Fields extends WireSaveableItems`

`$fields` manages the entire collection of `Field` objects, independent of any
Fieldgroup/Template. It's iterable directly:

```php
foreach ($fields as $field) {
    echo "$field->name ({$field->type}): $field->label\n";
}
```

Key methods (all confirmed in `Fields.php`):

- `$fields->get($key)` — get a `Field` by name or numeric ID (inherited from
  `WireSaveableItems`/`WireArray`, hookable per the `@method` doc on the class).
- `$fields->save($field)` — persist a Field's settings/data to the DB. Also handles table
  renames (on name change), Fieldtype changes (`changeFieldtype()`), and — if
  `Field::flagGlobal` is set — ensures the field is added to every template's fieldgroup.
- `$fields->delete($field)` — throws `WireException` if the field is still used by any
  fieldgroup, or if it's a system field (`flagSystem`).
- `$fields->isNative($name)` — true if `$name` collides with a reserved/native page
  property name (e.g. `id`, `parent`, `template`, `status` — see
  `Fields::$nativeNamesSystem`) and thus cannot be used as a field name.
- `$fields->findByTag($tag)` / `$fields->getTags($getFieldNames = false)` — tag-based
  lookup (3.0.106+).
- `$fields->findByType($type, array $options = [])` — find fields by Fieldtype class
  (with `inherit` option to match subclasses too), e.g.
  `$fields->findByType('FieldtypeFile')`.
- `$fields->findByFlag($flag)` — find fields having a given flag bit set (3.0.243+).
- `$fields->getAllNames($indexType = '')` — array of all field names.
- `$fields->getNumPages($field, $options)` / `getNumRows($field, $options)` — how many
  pages/rows have data populated for a given field, optionally filtered by
  `template` or `page`.
- `$fields->getFieldgroups($field)` / `getTemplates($field)` — which Fieldgroups/
  Templates use a given field.
- `$fields->getCompatibleFieldtypes($field)` — delegates to
  `$field->type->getCompatibleFieldtypes($field)`, used to populate the "change type"
  dropdown in the admin.

`Fields::makeItem($data)` (used when loading rows from the DB) is worth knowing about: it
resolves `$data['type']` to an actual `Fieldtype` module via `$fieldtypes->get(...)`, then
calls `$fieldtype->getFieldClass($data)` to determine which `Field` **subclass** to
instantiate — this is how `FieldtypePage` fields become `PageField` instances (see below)
rather than plain `Field` objects.

## The Fieldtype base class

Source: `wire/core/Fieldtype.php` — `abstract class Fieldtype extends WireData implements Module`

Every field "type" (Text, Integer, Image, Page reference, etc.) is a `Fieldtype` module.
Fieldtype instances are singular/shared (`isSingular()` returns `true`) and not
autoloaded by default (`isAutoload()` returns `false`, though some like `FieldtypePage`
and `FieldtypeRepeater` override this to `true` because they need to hook things at boot).

### Abstract / required method

```php
abstract public function sanitizeValue(Page $page, Field $field, $value);
```

Every Fieldtype must implement `sanitizeValue()` — called whenever a value is *set* onto a
Page (`$page->title = $value` runs through this). It should coerce/clean `$value` into
whatever runtime type the Fieldtype expects, or blank it out if invalid.

### Value lifecycle methods (all hookable, prefixed `___`)

Understanding the load/save cycle is central to Fieldtype behavior:

- **`getBlankValue(Page $page, Field $field)`** — NOT hookable (no `___` prefix). Returns
  the default page property. Default in base class: `''` (empty string). Overridden per
  Fieldtype, e.g. `FieldtypeCheckbox::getBlankValue()` returns `0`,
  `FieldtypeMulti::getBlankValue()` returns a blank `WireArray`. "Under no circumstances
  should this return NULL, because that is used by Page to determine if a field has been
  loaded" (per doc comment on `getDefaultValue()`).
- **`___loadPageField(Page $page, Field $field)`** — reads the raw row(s) from the
  field's DB table (`$field->table`) for the given page, returns the raw
  array/scalar value (or `null` if unavailable). Only called on-demand for fields that
  aren't autojoined.
- **`___wakeupValue(Page $page, Field $field, $value)`** — converts the raw DB value
  (from `loadPageField`) into the runtime/PHP value that ends up as `$page->fieldname`
  (e.g. turning a raw file-row array into a `Pagefiles` object).
- **`___sleepValue(Page $page, Field $field, $value)`** — the inverse of `wakeupValue()`:
  converts the runtime/"awake" value back into an array/int/string/float suitable for DB
  storage. Called by `___savePageField()`.
- **`___savePageField(Page $page, Field $field)`** — writes to the DB (`INSERT ... ON
  DUPLICATE KEY UPDATE`). Default implementation only executes if
  `$page->isChanged($field->name)`. Calls `sleepValue()` internally, and calls
  `deletePageField()` instead if `isDeleteValue()` is true for the value.
- **`isDeleteValue(Page $page, Field $field, $value)`** — whether a value is equivalent
  to "blank" and its DB row should just be deleted rather than stored (default: compares
  against `getBlankValue()`).
- **`isEmptyValue(Field $field, $value)`** — used by `PageFinder` for selector matching
  of "empty" fields; default is PHP `empty($value)`, but e.g. `FieldtypeInteger` treats
  `"0"` as non-empty when the field's `zeroNotEmpty` setting is on.
- **`___formatValue(Page $page, Field $field, $value)`** — applied only when
  `$page->of()` (output formatting) is enabled; e.g. runs configured Textformatters for
  text fields.
- **`___markupValue(Page $page, Field $field, $value = null, $property = '')`** — returns
  a ready-to-output string or `MarkupFieldtype` object; used for admin listings and
  `$field->type->markupValue(...)` style calls.
- **`___exportValue()` / `___importValue()`** — portable (web-service-friendly)
  export/import, default behavior mirrors sleep/wakeup.
- **`getDatabaseSchema(Field $field)`** — NOT hookable in the base class signature (no
  `___`, though subclasses freely override it since PHP doesn't enforce hookability on
  override). Returns an array describing the field's DB table columns/keys/engine, e.g.:

  ```php
  return array(
      'pages_id' => 'int UNSIGNED NOT NULL',
      'data'     => 'text NOT NULL', // overridden per Fieldtype
      'keys' => array(
          'primary' => 'PRIMARY KEY (`pages_id`)',
          'data'    => 'KEY data (`data`)',
      ),
      'xtra' => array(
          'append' => "ENGINE=$engine DEFAULT CHARSET=$charset",
          'all'    => true, // false if data storage extends beyond this table (repeaters, PageTable, etc.)
      ),
  );
  ```
- **`___createField(Field $field)`** — runs the `CREATE TABLE` for the field, built from
  `getDatabaseSchema()`.
- **`___deleteField(Field $field)`** — drops the table.
- **`getInputfield(Page $page, Field $field)`** — NOT hookable (no `___`); returns a new
  `Inputfield` module instance for editing the field. Base class default returns an
  `InputfieldText`; every concrete Fieldtype overrides this.
- **`getMatchQuery(...)`** — builds the SQL `WHERE` fragment used by `$pages->find()`
  selector matching against this field's table.

### Configuration-related hookable methods

- `___getConfigInputfields(Field $field)` — returns an `InputfieldWrapper` of settings
  specific to this Fieldtype (shown on the "Details" tab in the admin Field editor).
  Concrete Fieldtypes call `parent::___getConfigInputfields($field)` first, then append
  their own fields.
- `___getConfigArray(Field $field)` — same idea as an array-based definition instead of
  building `Inputfield` objects manually.
- `___getConfigAdvancedInputfields(Field $field)` — settings shown under the "Advanced"
  tab (autojoin, global, system, permanent checkboxes come from the base implementation).
- `___getConfigAllowContext(Field $field)` — array of config property names that may be
  overridden per-Fieldgroup/Template ("in context").
- `___exportConfigData()` / `___importConfigData()` — used for field export/import (JSON)
  in the admin and `$fields->...`.
- `___getCompatibleFieldtypes(Field $field)` — which other Fieldtype modules a field of
  this type may be converted to. Base class returns all non-`FieldtypeMulti` types;
  `FieldtypeMulti::___getCompatibleFieldtypes()` (override) returns only other
  `FieldtypeMulti` types — **you cannot convert between single-value and multi-value
  Fieldtypes** via the normal type-change mechanism (`Fields::___changeFieldtype()`
  explicitly throws `WireException` if you try).
- `___getFieldSetups()` — (3.0.213+) returns named presets (e.g. "single image" vs.
  "multiple images") that pre-populate settings when creating a new field of this type;
  applied via `Fields::___applySetupName()`.

### Fieldtype::get($key) special cases

`Fieldtype::get()` (not hookable) special-cases:

```php
$field->type->name;      // === $field->type->className(), e.g. "FieldtypeText"
$field->type->shortName; // className with "Fieldtype" prefix stripped, e.g. "Text"
$field->type->longName;  // module's "title" from getModuleInfo(), e.g. "Text"
```

### getDatabaseSchemaVerbose()

`Fieldtype::getDatabaseSchemaVerbose(Field $field, $property = '')` returns richer,
derived schema info than `getDatabaseSchema()`: `table`, `schema`, `all` (bool — whether
this table is the field's *only* storage), `cols`/`columns`, `engine`, `charset`,
`primaryKey`/`primaryKeys`, `otherKeys`, `transactions` (bool, true if InnoDB). Useful
for introspection without re-deriving these details yourself.

## FieldtypeMulti (base for multi-value Fieldtypes)

Source: `wire/core/FieldtypeMulti.php` — `abstract class FieldtypeMulti extends Fieldtype`

Base class for Fieldtypes that store more than one row per page (Image, File, Page
Reference (multi), Options (multi), Repeater's underlying storage is separate but its
sibling `FieldtypePage`/`FieldtypeOptions` extend this). Key differences from the plain
`Fieldtype` base:

- `getDatabaseSchema()` adds a `sort` int column and changes the primary key to
  `PRIMARY KEY (pages_id, sort)`.
- `getBlankValue()` returns a blank `WireArray`; `sanitizeValue()` requires (or coerces
  to) a `WireArray`.
- `___savePageField()` deletes all existing rows for the page and re-inserts them (since
  multi-value fields don't track individual row IDs by default) — unless
  `$field->paginationLimit` is set, in which case it delegates to
  `___savePageFieldRows()` (an UPDATE/INSERT-per-row strategy that requires the
  Fieldtype's schema to have exactly one primary key column, used e.g. by
  `FieldtypeComments`).
- `getMatchQuery()` adds special handling for a synthetic `count` subfield, e.g.
  `$pages->find("some_multi_field.count>=3")`.
- **Sorting/pagination support** is opt-in via two properties a subclass sets on itself
  in `__construct()`/`init()`:
  - `$this->useOrderByCols` (bool) — if true, the field gets an "Automatic sorting"
    config UI, and the field's `orderByCols` array property (e.g. `['sort']` or
    `['-date']`) controls `ORDER BY`.
  - `$this->usePagination` (bool) — if true (and `useOrderByCols` also true), adds a
    `paginationLimit` config setting (items per page) and the load query paginates using
    `$input->pageNum()`.
- `getLoadQueryAutojoin()` uses `GROUP_CONCAT(... SEPARATOR "\0,")` to autojoin all rows
  in one query (the `FieldtypeMulti::multiValueSeparator` constant is `"\0,"`).

## Enumerating fields and reading metadata

```php
// all fields
foreach ($fields as $field) {
    $type       = $field->type ? $field->type->className() : ''; // e.g. 'FieldtypeText'
    $required   = (bool) $field->required;
    $label      = $field->getLabel();       // language-aware, falls back to $field->name
    $desc       = $field->getDescription();
    echo "$field->name ($type): $label" . ($required ? ' *required*' : '') . "\n";
}

// a single field's full raw settings + custom data
$field = $fields->get('images');
print_r($field->getArray());           // WireData: dynamic/custom properties only
print_r($field->type->getDatabaseSchema($field)); // DB schema array
print_r($field->type->getDatabaseSchemaVerbose($field)); // richer schema info

// find all fields of a given type (including subclasses)
$imageFields = $fields->findByType('FieldtypeImage'); // array, keyed by name by default
```

## Fieldtype-specific notes and configuration properties

The properties below are the actual `attr('name', ...)` / `set(...)` keys used by each
module's `getConfigInputfields()` (or equivalent), confirmed by reading each module file.
Read them with `$field->get('propertyName')` or `$field->propertyName`.

### FieldtypeText / FieldtypeTextarea

Source: `wire/modules/Fieldtype/FieldtypeText.module`,
`wire/modules/Fieldtype/FieldtypeTextarea.module`

- `textformatters` (array) — module names of `Textformatter` modules applied on output
  (in order), e.g. `['TextformatterEntities']`. Read/apply manually via
  `$modules->get($name)->formatValue($page, $field, $value)`.
- `inputfieldClass` (string) — alternate Inputfield module class to use instead of the
  default (`InputfieldText` for `FieldtypeText`, `InputfieldTextarea` for
  `FieldtypeTextarea`). Must implement `InputfieldHasTextValue`.
- `FieldtypeTextarea` additionally has:
  - `contentType` (int) — one of `FieldtypeTextarea::contentTypeUnknown` (0),
    `contentTypeHTML` (1), or `contentTypeImageHTML` (2). Controls whether
    href/src-attribute "sleep/wakeup" URL abstraction and `<img>` quality-assurance
    (`MarkupQA`) run on save/load.
  - `htmlOptions` (array) — flags from `htmlLinkAbstract` (2), `htmlImageReplaceBlankAlt`
    (4), `htmlImageRemoveNoExists` (8), `htmlImageRemoveNoAccess` (16),
    `htmlImageLoadingLazy` (32).
- `FieldtypeText::getDatabaseSchema()` uses `text NOT NULL` with a `FULLTEXT KEY` plus a
  `data_exact` index; `FieldtypeTextarea` uses `mediumtext NOT NULL`.
- Compatible-fieldtype rules: `FieldtypeText`-derived types are cross-compatible with
  each other and with `FieldtypeSelector`.

### FieldtypeInteger

Source: `wire/modules/Fieldtype/FieldtypeInteger.module`

- `zeroNotEmpty` (bool/int) — if truthy, `0` is treated as distinct from blank for
  selector matching (`isEmptyValue()` returns `false` for `0`/`"0"`); this affects
  `$pages->find("field=0")` vs `$pages->find('field=""')` semantics.
- `defaultValue` (int|string) — default value assigned when no value entered.
- `sanitizeValue()` is quite forgiving: it strips currency symbols, handles scientific
  notation and hex, thousands separators, etc., ultimately returning an `int` or `''`.

### FieldtypeFloat / FieldtypeDecimal

Source: `wire/modules/Fieldtype/FieldtypeFloat.module`,
`wire/modules/Fieldtype/FieldtypeDecimal.module`

`FieldtypeFloat`:
- `precision` (int) — number of decimal digits to round to; negative disables rounding.
- `colType` (string) — `'float'` or `'double'`, changes the actual DB column type via
  `setColumnType()` (runs an `ALTER TABLE`).
- `zeroNotEmpty` — same meaning as `FieldtypeInteger` (reuses that Fieldtype's config
  Inputfield in its own `getConfigInputfields()`).

`FieldtypeDecimal` (fixed-precision, stored as SQL `DECIMAL`) has its own:
- `digits` (int) — total number of supported digits.
- `precision` (int) — digits after the decimal point.

### FieldtypeCheckbox / FieldtypeToggle

Source: `wire/modules/Fieldtype/FieldtypeCheckbox.module`,
`wire/modules/Fieldtype/FieldtypeToggle.module`

`FieldtypeCheckbox` — a single ON(1)/OFF(0) `tinyint` value, no notable config
properties beyond the standard ones; blank value is `0` (not empty string). Compatible
type-conversion allows converting to/from `FieldtypeToggle` if installed.

`FieldtypeToggle` — a more flexible 2/3-state toggle:
- Value constants: `valueNo` (0), `valueYes` (1), `valueOther` (2), `valueUnknown` ('').
- `formatType` (int) — controls formatted output style: `formatNone` (0), `formatBoolean`
  (1), `formatString` (2), `formatEntities` (3).

### FieldtypeDatetime

Source: `wire/modules/Fieldtype/FieldtypeDatetime.module`

- `dateOutputFormat` (string) — a PHP `date()` (or `strftime()`) format string used when
  output-formatting the value, e.g. `'Y-m-d'` (the `defaultDateOutputFormat` constant).
  Built in the admin from two helper (runtime-only, underscore-prefixed, thus not
  persisted) selects: `_dateOutputFormat` and `_timeOutputFormat`. Multi-language capable
  (`dateOutputFormat{$languageID}` per-language override when `$languages` installed).
- `WireDateTime::getDateFormats()` / `getTimeFormats()` supply the option lists used to
  build the format string.

### FieldtypeFile

Source: `wire/modules/Fieldtype/FieldtypeFile/FieldtypeFile.module` and
`wire/modules/Fieldtype/FieldtypeFile/config.php`

Base class for file-upload fields (`FieldtypeImage` extends it). Extends
`FieldtypeMulti`. Config properties (from `config.php` `getConfigInputfields()` /
`getConfigAdvancedInputfields()`):

- **`extensions`** (string) — space-separated list of allowed file extensions, no dots
  or commas, case-insensitive (e.g. `"pdf doc docx xls xlsx gif jpg jpeg png"`). Default
  differs by class — `FieldtypeFile::getDefaultFileExtensions()` (overridable per
  subclass; `FieldtypeImage::getDefaultFileExtensions()` returns `"gif jpg jpeg png"`).
  Read the effective default with `$field->type->get('defaultFileExtensions')`
  (implemented via `Fieldtype::get()` override in `FieldtypeFile`).
- `okExtensions` (array) — extensions whitelisted to bypass `FileValidator` module
  checks.
- **`maxFiles`** (int) — maximum number of files/images; `0` = unlimited, `1` = single
  file. Also drives whether the formatted value dereferences as a single item.
- `textformatters` (array) — text formatters applied to file *descriptions*.
- `entityEncode` (bool) — shortcut that (if checked) ensures `TextformatterEntities` is
  in the `textformatters` list for descriptions.
- `useTags` (int) — `FieldtypeFile::useTagsOff` (0), `useTagsNormal` (1),
  `useTagsPredefined` (8), or `useTagsNormal|useTagsPredefined` combined — controls file
  tagging UI.
- `tagsList` (string) — predefined tag list text, shown when `useTags` includes
  `useTagsPredefined`.
- **`outputFormat`** (int) — how the formatted value dereferences:
  `outputFormatAuto` (0, default — single item when `maxFiles==1` else array),
  `outputFormatArray` (1, always `Pagefiles`/`Pageimages`), `outputFormatSingle` (2,
  always single `Pagefile`/`Pageimage` or null), `outputFormatString` (30, rendered
  markup/text via `outputString`).
- `outputString` (string) — template string used when `outputFormat` is
  `outputFormatString`.
- `defaultValuePage` (int/Page) — page whose value is used as a fallback/default.
- `inputfieldClass` (string) — Inputfield module to use (default derived from class name,
  e.g. `InputfieldFile`/`InputfieldImage`).
- Module-level (not per-field) config: `allowFieldtypes` (array) — which Fieldtype
  modules are allowed as **custom per-file fields** (file description + tags + arbitrary
  custom fields attached to each file). Default allow-list
  (`FieldtypeFile::$defaultAllowFieldtypes`) includes Checkbox, Datetime, Email,
  FieldsetOpen/Close, Float, Integer, Page, PageTitle(+Language), Text(+Language),
  Textarea(+Language), Toggle, URL.
- `fileSchema` (int, internal/advanced) — bitmask of `fileSchemaTags` (1),
  `fileSchemaDate` (2), `fileSchemaFiledata` (4), `fileSchemaFilesize` (8) indicating
  which optional DB columns exist for this field's table (auto-upgraded over time).

`getInputfield()` warns (via `$this->error()`/`$this->message()`) if `extensions` isn't
yet set — a newly created File/Image field is "not ready" until extensions are
configured and the field re-saved.

```php
$field = $fields->get('images');
$exts    = $field->get('extensions');   // e.g. "gif jpg jpeg png"
$maxFiles = (int) $field->get('maxFiles');
```

### FieldtypeImage

Source: `wire/modules/Fieldtype/FieldtypeImage/FieldtypeImage.module`

Extends `FieldtypeFile` (implements `FieldtypeHasFiles`, `FieldtypeHasPageimages`).
Inherits all `FieldtypeFile` config properties (`extensions`, `maxFiles`, `outputFormat`,
etc.) — default `extensions` is `"gif jpg jpeg png"` per
`getDefaultFileExtensions()`. Adds no new field-config Inputfields of its own beyond the
parent (`___getConfigInputfields()`/`___getConfigAdvancedInputfields()` both just call
`parent::...()`), but adds DB columns for image metadata: `width`, `height`, `ratio`
(gated by the `fileSchemaDimensions` (256) flag in `fileSchema`, auto-added via
`updateDatabaseSchema()` if missing).

Value class is `Pageimages` (a `Pagefiles` subclass) containing `Pageimage` objects
(`Pagefile` subclass). Confirmed properties/methods on `Pagefile`/`Pageimage`
(`wire/core/Pagefile.php`, `wire/core/Pageimage.php` doc blocks + method list):

- `Pagefile`: `url`, `httpUrl`, `filename` (disk path), `name`/`basename`, `hash`,
  `description` (string, per-file, language-aware via `description($language)`), `tags`
  (string) + `tagsArray`, `ext`, `filesize` / `filesizeStr`, `created`/`modified`
  (timestamps) + `createdStr`/`modifiedStr`, `created_users_id`/`modified_users_id` +
  `createdUser`/`modifiedUser`, `filedata` (array, custom per-file field storage),
  `pagefiles` (owning `Pagefiles`), `page`, `field`.
- `Pageimage` (extends `Pagefile`): `width`, `height` (int, also callable as
  `width($n)`/`height($n)` to get a resized variation's dimension), `ratio` (float),
  `focus` (array: `top`, `left`, `zoom`, `default`), `suffix`/`suffixStr` (variation
  suffixes), `alt` (alias for `description`), `src` (alias for `url`), `webp`. Resizing
  methods: `size($width, $height, $options)`, `width()`, `height()`, `maxWidth()`,
  `maxHeight()`, `maxSize()`, `crop()`, `getVariations()`.

### FieldtypePage (Page Reference)

Source: `wire/modules/Fieldtype/FieldtypePage.module`,
`wire/modules/Inputfield/InputfieldPage/InputfieldPage.module`,
`wire/modules/Fieldtype/PageField.php`

Extends `FieldtypeMulti` (implements `ConfigurableModule`). `getFieldClass()` returns
`'PageField'`, so fields of this type are instantiated as `PageField` (a `Field`
subclass) rather than plain `Field` — this only adds one helper method
(`getTemplateAndParentIds()`) and doesn't change basic `get()`/`set()` behavior.

Value-dereferencing constants (`derefAsPage` setting):

- `FieldtypePage::derefAsPageArray` (0) — value is always a `PageArray` (default).
- `FieldtypePage::derefAsPageOrFalse` (1) — single `Page` or boolean `false` if empty.
- `FieldtypePage::derefAsPageOrNullPage` (2) — single `Page` or `NullPage` if empty.

Config properties, confirmed in `FieldtypePage.module` (`derefAsPage`, `allowUnpub`) and
`InputfieldPage.module` (`parent_id`, `template_id`, `template_ids`, `findPagesSelect`,
`findPagesSelector`, `findPagesCode` [deprecated], `labelFieldName`, `labelFieldFormat`,
`inputfield`, `addable`) — full authoritative list from the `PageField` class doc block:

```php
/**
 * Configured with FieldtypePage
 * @property int      $derefAsPage
 * @property int|bool $allowUnpub
 *
 * Configured with InputfieldPage
 * @property int      $template_id
 * @property array    $template_ids
 * @property int      $parent_id
 * @property string   $inputfield          Inputfield class used for input
 * @property string   $labelFieldName      Field name to use for label ("." means labelFieldFormat is used instead)
 * @property string   $labelFieldFormat    Formatting string for $page->getMarkup() as alt to labelFieldName
 * @property string   $findPagesCode
 * @property string   $findPagesSelector
 * @property string   $findPagesSelect     Same as findPagesSelector, but built interactively with InputfieldSelector
 * @property int|bool $addable
 * @property-read string $inputfieldClass
 * @property array    $inputfieldClasses
 */
```

Notes:
- `parent_id` and `template_id`/`template_ids` (used together or separately) constrain
  which pages are selectable. `findPagesSelect`/`findPagesSelector` (a raw selector
  string, e.g. `"parent=/products/, template=product, sort=name"`) is an alternate/
  additional constraint mechanism — per the admin description, parent/template
  selections (if present) are *still used for validation* even when a custom find
  selector is also set.
- `labelFieldName` controls what's shown for each selectable/selected page in the input
  UI; a value of `"."` means "use `labelFieldFormat`" (a `$page->getMarkup()`-style
  format string) instead.
- `inputfield` (note: this is the property name read by `PageField`, distinct from the
  more commonly seen `inputfieldClass` used by other Fieldtypes) determines which
  Inputfield module renders the field (e.g. `InputfieldPageListSelect`,
  `InputfieldSelect`, `InputfieldAsmSelect`, `InputfieldPageAutocomplete`, etc.) —
  `getInputfield()` in `FieldtypePage.module` always instantiates `InputfieldPage` itself
  first, which then internally loads the specific configured input type.
- `addable` (bool) — whether users can create new pages directly from the field's input.
- Cross-type compatibility is restricted to other `FieldtypePage`-derived Fieldtypes only
  (`___getCompatibleFieldtypes()` strips out anything not `instanceof FieldtypePage`).

```php
$field = $fields->get('related_articles');
$parentId    = $field->get('parent_id');
$templateIds = FieldtypePage::getTemplateIDs($field, true); // helper, CSV or array form
$derefMode   = $field->get('derefAsPage'); // 0, 1, or 2 -- see constants above
```

### FieldtypeOptions (Select Options)

Source: `wire/modules/Fieldtype/FieldtypeOptions/FieldtypeOptions.module`,
`SelectableOption.php`, `SelectableOptionArray.php`, `SelectableOptionManager.php`

Extends `FieldtypeMulti`. Unlike most Fieldtypes, the selectable options themselves are
**not** stored as a simple field-config array — they live in a dedicated table
(`fieldtype_options`, referenced in `getMatchQuerySort()`) managed by a
`SelectableOptionManager` instance, exposed on the Fieldtype as `$field->type->manager`
(`FieldtypeOptions::get('manager')`).

Each option is a `SelectableOption` (`WireData` subclass) with properties (from its class
doc block and `$defaults`):

```php
/**
 * @property int    $id
 * @property int    $sort
 * @property string $title
 * @property string $value
 */
```

`SelectableOption::getTitle()` / `getValue()` are the **language-aware, output-formatted**
accessors (fall back to default-language `title`/`value` when a translation is blank, and
HTML-entity-encode when the option's `of()` output-formatting flag is on) — prefer these
over reading `->title`/`->value` directly when rendering.

Programmatically reading the option list for a field:

```php
$field = $fields->get('color');
/** @var FieldtypeOptions $fieldtype */
$fieldtype = $field->type;
$options = $fieldtype->getOptions($field); // SelectableOptionArray of SelectableOption
foreach ($options as $option) {
    echo "$option->id: {$option->getTitle()} ({$option->getValue()})\n";
}
```

(`FieldtypeOptions::getOptions()` / `setOptions()` / `addOptions()` / `deleteOptions()`
are documented in the module as the public API, delegating to `$this->manager`.)

Other config properties:
- `inputfieldClass` (string) — which Inputfield renders the options (e.g.
  `InputfieldSelect`, `InputfieldCheckboxes`, `InputfieldRadios`, `InputfieldSelectMultiple`,
  `InputfieldTextTags`). `getInputfieldClassOptions()` enumerates all installed modules
  implementing `InputfieldHasSelectableOptions`.
  determined by whether the module implements `InputfieldSelectMultiple` /
  `InputfieldHasSortableValue`.
- `initValue` (mixed) — a pre-selected default option value, auto-applied to new/blank
  pages if the field is required and unset.
- The raw editable option text (as edited in the admin, one option per line,
  `value|title` syntax) is stored per-language as `_options` / `_options__{languageID}`
  dynamic properties, exported as `export_options` (see `___exportConfigData()`).

Value type is `SelectableOptionArray` (a `WireArray` of `SelectableOption`), even when
only one option is selectable — code that expects a single value should call
`->first()` or check `$field->get('inputfieldClass')` to see if it's a single-value input
type (this is literally what `FieldtypeOptions::___markupValue()` does internally).

### FieldtypeRepeater

Source: `wire/modules/Fieldtype/FieldtypeRepeater/FieldtypeRepeater.module` and
`config.php`

Extends the plain `Fieldtype` (not `FieldtypeMulti`) and implements
`ConfigurableModule`, `FieldtypeDoesVersions`. **This is the most structurally complex
core Fieldtype**: each repeater item is actually a real hidden `Page` (stored under an
admin-hidden `repeaters` tree, using a template named `repeater_{fieldname}`), and the
field's value is a `RepeaterPageArray` (a `PageArray` subclass) of `RepeaterPage`
objects — so a repeater field is, under the hood, a `FieldtypePage`-like relationship to
auto-managed pages, not literal rows in the field's own table.

Confirmed config properties (from `config.php`):

- `template_id`, `parent_id` (int) — the auto-created repeater template and parent page
  under `//repeaters/for-field-{name}/` (`FieldtypeRepeater::templateNamePrefix`,
  `fieldPageNamePrefix`, `repeatersRootPageName` constants document the naming scheme).
- `repeaterFields` (array) — the field names that make up each repeater item (added via
  the field's editor, which really edits the auto-generated `repeater_*` template).
- `repeaterTitle` (string) — format for the item's summary/label shown when collapsed.
- `repeaterDepth` (int) + `familyFriendly` (bool) + `familyToggle` (bool) — indent/
  outline-style nesting support for repeater items.
- `repeaterAddLabel` (string) — label text for the "add new item" link/button.
- `repeaterCollapse` (int) — one of `collapseExisting` (0, default), `collapseAll` (1),
  `collapseNone` (3) — controls default open/closed state of items in the editor.
- `repeaterLoading` (int) — one of `loadingNew` (0), `loadingAll` (1), `loadingOff` (2) —
  controls AJAX/dynamic loading behavior of items in the page editor.
- `rememberOpen` (bool), `accordionMode` (bool), `loudControls` (mixed), `noScroll`
  (bool) — editor UX behavior toggles.
- `repeaterMaxItems` / `repeaterMinItems` (int) — item count constraints (`0` = no
  limit, per `defaultRepeaterMaxItems` constant).
- `lazyParents` (bool) — "use fewer pages for storage" performance option, which changes
  how repeater item pages are parented (older versions created a new parent page per
  owning page; lazy mode shares parents to reduce page-tree bloat).

Gotchas worth flagging for anyone working with Repeater programmatically:
- Values are real `Page` objects (`RepeaterPage extends Page`), so code that walks the
  page tree, exports pages, or bulk-deletes pages needs to account for the hidden
  `repeaters` branch.
- Item page status matters and is meaningful, per the class doc comment: "Unpublished &
  Hidden" = a "ready" (not-yet-saved) item appearing only in the unformatted value;
  "Unpublished & On" = publish is pending; "Unpublished & NOT On" = the item was
  explicitly unpublished/removed by the user. Unpublished-or-hidden items are excluded
  from the **formatted** `PageArray` value but present in the unformatted one.
- Because items are pages under a real template, adding/removing fields from a repeater
  is really adding/removing fields from that auto-generated `repeater_*` template's
  fieldgroup — this is why deep/nested repeaters can get expensive.
- `FieldtypeFieldsetPage.module` (in the same directory) is a related but distinct
  Fieldtype — the basis for the "Custom Fieldset (Page)" field type, storing a single
  (non-repeating) page's worth of fields rather than a repeatable array.

## Practical recipes

```php
// list every field with its Fieldtype and required status
foreach ($fields as $field) {
    printf(
        "%-20s %-20s required=%s\n",
        $field->name,
        $field->type ? $field->type->className() : '(no type)',
        $field->required ? 'yes' : 'no'
    );
}

// find every field of a given category
foreach ($fields->findByType('FieldtypeFile') as $field) {
    echo "$field->name allows: " . $field->get('extensions') . "\n";
}

// inspect an Options field's choices
$field = $fields->get('status_options');
if ($field->type instanceof FieldtypeOptions) {
    foreach ($field->type->getOptions($field) as $opt) {
        echo "{$opt->id}: {$opt->getTitle()}\n";
    }
}

// inspect a Page-reference field's constraints
$field = $fields->get('related_pages');
if ($field->type instanceof FieldtypePage) {
    echo 'parent_id: ' . $field->get('parent_id') . "\n";
    echo 'template_id: ' . $field->get('template_id') . "\n";
    echo 'dereference mode: ' . $field->get('derefAsPage') . "\n";
}

// dump a field's full DB schema
print_r($field->type->getDatabaseSchema($field));
```

## Sources consulted

- `wire/core/Field.php`
- `wire/core/Fields.php`
- `wire/core/Fieldtype.php`
- `wire/core/FieldtypeMulti.php`
- `wire/core/Fieldtypes.php` (brief — `$fieldtypes` API var, `WireArray` of Fieldtype modules)
- `wire/core/Pagefile.php`, `wire/core/Pageimage.php` (class doc blocks + method signatures)
- `wire/modules/Fieldtype/FieldtypeText.module`
- `wire/modules/Fieldtype/FieldtypeTextarea.module`
- `wire/modules/Fieldtype/FieldtypeInteger.module`
- `wire/modules/Fieldtype/FieldtypeFloat.module`
- `wire/modules/Fieldtype/FieldtypeDecimal.module`
- `wire/modules/Fieldtype/FieldtypeCheckbox.module`
- `wire/modules/Fieldtype/FieldtypeToggle.module`
- `wire/modules/Fieldtype/FieldtypeDatetime.module`
- `wire/modules/Fieldtype/FieldtypeFile/FieldtypeFile.module`
- `wire/modules/Fieldtype/FieldtypeFile/config.php`
- `wire/modules/Fieldtype/FieldtypeImage/FieldtypeImage.module`
- `wire/modules/Fieldtype/FieldtypePage.module`
- `wire/modules/Fieldtype/PageField.php`
- `wire/modules/Inputfield/InputfieldPage/InputfieldPage.module`
- `wire/modules/Fieldtype/FieldtypeOptions/FieldtypeOptions.module`
- `wire/modules/Fieldtype/FieldtypeOptions/SelectableOption.php`
- `wire/modules/Fieldtype/FieldtypeOptions/SelectableOptionManager.php` (method list only)
- `wire/modules/Fieldtype/FieldtypeRepeater/FieldtypeRepeater.module`
- `wire/modules/Fieldtype/FieldtypeRepeater/config.php`

### Known gaps / not independently verified in this pass

- `SelectableOptionManager.php`'s internal method bodies (`getOptions()`,
  `setOptions()`, etc.) were located and their signatures confirmed, but not read
  line-by-line — the documented behavior of `getOptions()`/`setOptions()` above is based
  on the public method list, doc comments, and their call sites in
  `FieldtypeOptions.module`, not a full read of the manager's internals (e.g. exact
  caching/DB query behavior of `SelectableOptionManager`).
- `Pagefiles`/`Pageimages` container classes (as opposed to the individual
  `Pagefile`/`Pageimage` item classes) were not read directly; properties listed for
  individual files/images are confirmed from `Pagefile.php`/`Pageimage.php` class doc
  blocks and method signatures.
- `FieldtypeRepeaterVersions.php`, `RepeaterPage.php`, `RepeaterPageArray.php`, and
  `FieldsetPage.php` (the FieldtypeFieldsetPage support class) were not read in detail —
  the Repeater section above is based on `FieldtypeRepeater.module`'s top doc comment,
  constants, and `config.php`, not the full item/page-array implementation.
- Other core Fieldtypes not covered by the task brief (FieldtypeEmail, FieldtypeURL,
  FieldtypePageTitle, FieldtypeComments, FieldtypePageTable, FieldtypeModule,
  FieldtypeFieldsetOpen/Close/TabOpen, FieldtypeSelector, FieldtypePassword) were not
  read for this document.
