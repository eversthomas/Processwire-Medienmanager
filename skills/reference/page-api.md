# Page API

The `Page` class (`wire/core/Page.php`) is the object representing every page in a ProcessWire
site. Its behavior is split across several helper classes that `Page` delegates to internally:

- `PageProperties.php` — static maps of native property names to their access method/type
- `PageValues.php` — get/set logic for custom (Fieldtype-backed) field values, output formatting
- `PageComparison.php` — `is()`, `matches()`, `matchesDatabase()`
- `PageTraversal.php` — `children()`, `parent()`, `siblings()`, `next()`, `prev()`, url methods, etc.
- `PageAccess.php` — resolves the access-control parent/template/roles for a page (not the
  `viewable()/editable()/addable()` methods themselves — see the Permissions section below)

The `$pages` API variable (`wire/core/Pages.php`) is the manager for finding, saving, adding,
deleting, and trashing pages. Its actual save/add/delete/clone mechanics live in
`wire/core/PagesEditor.php`, which `Pages` delegates to via `$pages->editor()`. Loading and
in-memory caching of pages is handled by `wire/core/PagesLoader.php` and
`wire/core/PagesLoaderCache.php`. Automatic page-name generation is handled by
`wire/core/PagesNames.php`.

All code references below cite the actual core files read to produce this document.

## Reading a field value

`Page::get()` (`Page.php` ~line 946) is the canonical way to read any property or custom field:

```php
$title = $page->get('title');

// shorthand via __get(), which just calls get() (Page.php ~line 1549)
$title = $page->title;
```

`get()` supports much more than a plain field name (per its doc block, `Page.php` ~lines 890-945):

```php
// first non-empty of several fields
$headline = $page->get('headline|title');

// string with {tag} placeholders
$str = $page->get('{createdStr}: {title} - {summary}');

// sub-properties of object properties
$parentTitle = $page->get('parent.title');

// guaranteed-iterable value, indexed access, filtered selectors (3.0.205+)
$images = $page->get('image[]');
$file = $page->get('files[0]');       // or $page->get('files.first')
$categories = $page->get('categories[title%=design]'); // PageArray
```

Internally, `get()` first checks `PageProperties::$baseProperties` (`PageProperties.php` line 73)
to see if the key is a native property (mapped to a real property `p`, a method `m`, a settings
array key `s`, a traversal method `t`, etc.), then falls through to `$this->values()->getFieldValue()`
(`PageValues.php` line 877) for custom Fieldtype-backed fields.

### `$page->fields` vs the `$fields` API variable

This is a common point of confusion. `PageProperties::$basePropertiesAlternates` maps
`'fields' => 'fieldgroup'` (`PageProperties.php` line 169), and the class doc-block for `Page`
states explicitly:

```
@property Fieldgroup $fields All the Fields assigned to this page (via its template). Returns a
Fieldgroup. #pw-advanced
```

So **`$page->fields` returns the page's `Fieldgroup`** (the fields assigned via its template) —
it is completely unrelated to the global `$fields` API variable, which is the `Fields` manager
object for the whole system (`$fields->get('title')` etc.). Do not confuse the two.

### Output formatting — `of()`

Every `Page` has an `outputFormatting` boolean flag (`Page.php` line 523), defaulting to `false`
on newly-loaded pages retrieved via most API calls, but conventionally treated as "on" for pages
used in front-end templates and "off" for pages you intend to manipulate/save.

```php
public function of($outputFormatting = null)          // Page.php line 3784
public function setOutputFormatting($outputFormatting = true)  // Page.php line 3737
public function outputFormatting()                     // Page.php line 3751 (getter only)
```

`of()` both gets and sets: called with no argument it returns the current state; called with a
bool it sets the state and returns the *previous* state.

When output formatting is **on**, `get()` (via `PageValues::getFieldValue()` /
`formatFieldValue()`, `PageValues.php` lines 979-1002) runs the Fieldtype's `formatValue()` hook
on the value (e.g. Textformatters on a Textarea field, entity-encoding, trimming multi-value
fields to single, etc.) before returning it. When output formatting is off, you get the raw,
unformatted value as stored/loaded.

```php
public function getUnformatted($key)   // Page.php line 1507 — get() with of(false) forced, then restored
public function getFormatted($key)     // Page.php line 1533 — get() with of(true) forced, then restored
public function setUnformatted($key, $value)  // Page.php line 1478 — set() with of(false) forced
```

`getUnformatted()` and `setUnformatted()` are convenience wrappers that toggle `outputFormatting`
around a single `get()`/`set()` call without disturbing the page's actual output-formatting state
otherwise.

**Gotcha — corrupted-field detection.** If output formatting is **on** and you `set()` a value for
a field where the formatted representation differs from what you're assigning,
`PageValues::setFieldValue()` (`PageValues.php` lines 1095-1116) detects the mismatch and marks
the page `Page::statusCorrupted`, recording the field name in `$page->_statusCorruptedFields`.
`PagesEditor::isSaveable()` (`PagesEditor.php` lines 175-193, 202-223) then refuses to save the
page and throws, with the message pointing at
`"Call $page->of(false); before getting/setting values that will be modified and saved."` This is
exactly why the standard idiom for editing pages via the API is:

```php
$p = $pages->get('/some-page/');
$p->of(false);          // turn off output formatting before editing
$p->title = 'New Title';
$p->save();
```

## Writing a field value

```php
public function set($key, $value)  // Page.php line 711
public function __set($key, $value) // Page.php line 1561 — shorthand, calls set()
```

```php
$page->set('title', 'New Title');
// equivalent shorthand
$page->title = 'New Title';
```

`set()` special-cases many native properties (`id`, `name`, `status`, `parent`/`parent_id`,
`template`/`templates_id`, `created`/`modified`/`published`, `sortfield`, etc. — see the full
`switch` at `Page.php` lines 723-816) and otherwise delegates to
`$this->values()->setFieldValue($this, $key, $value, $this->isLoaded)` (`Page.php` line 815) for
custom fields, which is the same as calling the internal `setFieldValue()` method directly:

```php
public function setFieldValue($key, $value, $load = true)  // Page.php line 885
```

There are also two lower-level setters:

```php
public function setQuietly($key, $value)  // Page.php line 835 — sets without change tracking or exceptions
public function setForced($key, $value)   // Page.php line 860 — bypasses checks, e.g. setting even with no template
```

`setQuietly()` is used throughout the core (e.g. in clone/setupNew) to populate values without
recording them as "changed," which matters because `save()` and `isChanged()` rely on tracked
changes.

**Gotcha — a template must be set before setting custom field values.** `PageValues::setFieldValue()`
throws (or, in debug mode, substitutes the 404 template so the page can still be deleted) if
`$page->template()` is empty when you try to set a Fieldtype-backed field
(`PageValues.php` lines 1023-1034):

```
"You must assign a template to page $page before setting '$name' field."
```

So always set `template` (and usually `parent`) before setting any custom fields.

### Saving after a set

```php
public function save($field = null, array $options = array())  // Page.php line 2427
```

- `$page->save()` with no arguments saves the whole page (delegates to `$pages->save($this, $options)`).
- `$page->save('summary')` saves only that one custom field, delegating to `$pages->saveField($this, 'summary', $options)` (checked via `$this->hasField($field)`, `Page.php` line 2440).
- If the given `$field` is not a real Fieldtype-backed field, `save()` falls back to saving only
  native properties by setting `$options['noFields'] = true` (`Page.php` lines 2445-2448).

```php
public function saveFields($fields, array $options = array())  // Page.php line 2462
```

Saves several named fields at once — accepts an array or CSV/space-separated string of field
names, delegates to `$pages->saveFields($page, $fields, $options)`.

```php
public function setAndSave($key, $value = null, array $options = array())  // Page.php line 2504
```

A convenience combo of set + save that automatically toggles output formatting off/back for you
(`Page.php` lines 2512-2523), so you don't have to call `of(false)` yourself:

```php
$page->setAndSave('summary', 'When nothing is done, nothing is left undone.');

$page->setAndSave([
  'title' => 'It is Friday again',
  'subtitle' => 'Here is another new blog post',
]);
```

Note from the code: if you pass an array with more than one key, `setAndSave()` calls the full
`$this->save($options)` (whole-page save), not a single-field save — the single-field save path
(`$this->save($property, $options)`) is only used when exactly one key/value pair is given
(`Page.php` lines 2505-2522).

### `$pages->save()` options

`Pages::___save()` (`Pages.php` line 856) delegates to `PagesEditor::save()`
(`PagesEditor.php` line 458). Documented `$options` (from both doc blocks):

- `uncacheAll` (bool, default `true`) — clear the memory cache after saving
- `resetTrackChanges` (bool, default `true`) — reset the page's change-tracking after save
- `quiet` (bool, default `false`) — when true, `modified`/`modified_users_id` are **not**
  updated to "now"/current user
- `adjustName` (bool, default `true`) — ensure the page name stays unique within its parent
- `forceID` (int, default `0`) — use this ID instead of auto-assigning one (new page) or the
  current ID
- `ignoreFamily` (bool, default `false`) — bypass allowed parent/child template family checks
- `noHooks` (bool, default `false`) — skip before/after save hooks
- `noFields` (bool, default `false`) — save only native properties, skip custom field data

```php
$p = $pages->get('/festivals/decatur/beer/');
$p->of(false);
$p->title = 'Decatur Beer Festival';
$p->summary = 'Come and enjoy fine beer and good company at the Decatur Beer Festival.';
$pages->save($p);
```

Internally `PagesEditor::save()` (`PagesEditor.php` lines 458-533):

1. Computes `$isNew = $page->isNew()`; if new, calls `$this->pages->setupNew($page)` first
   (assigns parent if missing, generates a unique name if missing, assigns sort, populates field
   default values — `PagesEditor.php` lines 361-408).
2. Calls `isSaveable()` and throws `WireException` with a specific reason if not saveable.
3. Handles trash/restore transitions if the page's parent changed to/from the trash.
4. Checks/adjusts the page name for uniqueness (unless `_hasUniqueName` was already set).
5. Runs the actual DB write via `savePageQuery()` then `savePageFinish()`.

## Creating a new page

There are three supported patterns in the core, all converging on the same `PagesEditor` code
paths.

### 1. `$pages->add($template, $parent, $name = '', array $values = array())`

`Pages::___add()` (`Pages.php` line 954) → `PagesEditor::add()` (`PagesEditor.php` line 83).

```php
// Minimal — a name is auto-assigned from the current time if none is given
$building = $pages->add('skyscraper', '/skyscrapers/atlanta/');

// With a name/title
$building = $pages->add('skyscraper', '/skyscrapers/atlanta/', 'Symphony Tower');

// With several field values at once
$building = $pages->add('skyscraper', '/skyscrapers/atlanta/', [
  'title' => 'Symphony Tower',
  'summary' => 'A 41-story skyscraper located at 1180 Peachtree Street',
  'height' => 657,
  'floors' => 41,
]);
```

Notes from the actual implementation (`PagesEditor.php` lines 83-149):

- `$template` may be a `Template` object or a name string; it's looked up via `$this->wire()->templates->get($template)` if not already an object.
- `$parent` may be a path, ID, or `Page` object.
- If `$values['title']` is empty, whatever you passed as `$name` becomes the title (default
  `"Untitled Page"` if nothing given at all) — `PagesEditor.php` lines 105-119.
- `status` may be included in `$values` and is applied before the first save.
- The page is saved once immediately after title/name/status are set — *before* the rest of
  `$values` are applied — specifically "in case any fieldtypes require the page to have an ID
  already (like file-based)" (comment at `PagesEditor.php` line 126). Then remaining `$values` are
  set and the page is saved again.
- After saving, the method reloads a fresh copy of the page via `$pages->getById()`
  (`PagesEditor.php` lines 138-146), so the object you get back is not literally the same
  in-memory instance you'd have built by hand.
- Returned page has output formatting **off** (per the doc block, `Pages.php` line 949).

### 2. `$pages->new($selector)` / selector-array form (3.0.191+)

`Pages::___new()` (`Pages.php` line 1011) offers a 1-argument interface built on
`PagesEditor::newPageOptions()` + `PagesEditor::add()`:

```php
$p = $pages->new("template=category, parent=/categories/, title=New Category");

$p = $pages->new([
  'template' => 'category',
  'parent' => '/categories/',
  'title' => 'New Category',
]);

// parent/name can be auto-derived from a path
$p = $pages->new('path=/blog/posts/foo-bar-baz');
$p = $pages->new('/blog/posts/foo-bar-baz'); // leading '/' implies path=
```

Per the doc block (`Pages.php` lines 967-980): a `template` is required unless it can be derived
from `parent`'s allowed child templates; a `parent` is required unless derivable from `template`'s
allowed parent templates or from `path`. If a `path` is given but no `name`/`parent`, both are
derived from it. If `title` is given but no `name`/`path`, `name` is derived from `title`. Name
collisions get an incrementing numeric suffix (e.g. `foo` → `foo-1`). With nothing to derive a
name from, `"untitled-page"` is used.

### 3. `$pages->newPage()` + manual `set()` + `save()` (unsaved page construction)

`Pages::newPage($options = array())` (`Pages.php` line 1964) constructs (but does not save) a
`Page` instance:

```php
public function newPage($options = array())
```

```php
// bare new Page (no template) — equivalent to `new Page()`
$p = $pages->newPage();

// with options: template, parent, other native/custom properties
$p = $pages->newPage([
  'template' => 'skyscraper',
  'parent' => '/skyscrapers/atlanta/',
  'title' => 'Symphony Tower',
]);
$p->save();
```

If `$options` is empty, it literally returns `$this->wire(new Page())` (`Pages.php` line 1966).
Otherwise it resolves `template`/`parent`/`pageClass` via `newPageOptions()`, instantiates the
correct `Page`-derived class (auto-detected from the template unless `pageClass` is given), sets
`$page->parent` and any remaining options via `setArray()`, and returns the (unsaved) page.

You can also build a page fully manually with `new Page()`:

```php
$p = new Page();
$p->template = 'basic-page';   // must be set before custom fields can be set (see gotcha above)
$p->parent = wire('pages')->get('/some-parent/');
$p->title = 'Hello World';
$p->save();
```

### Required properties before `save()`

`PagesEditor::isSaveable()` (`PagesEditor.php` lines 163-231) is the actual gate. A page fails to
save if:

- It's a `NullPage`
- It has no `parent_id`/`parent` and isn't the homepage (`id === 1`)
- It has no `template`
- Its `name` is empty (and it isn't the homepage)
- It has `Page::statusCorrupted` fields present in its tracked changes (see output-formatting
  gotcha above)
- It's the homepage (`id === 1`) but its template doesn't `useRoles` or lacks the `guest` role

If a `parent` changed on an existing page, `isSaveable()` additionally checks `isMoveable()`
(`PagesEditor.php` line 246) unless `ignoreFamily` was passed.

`PagesEditor::setupNew()` (`PagesEditor.php` lines 361-408), run automatically for new pages
inside `save()`, will auto-assign a parent (from the template's allowed parent templates) and
auto-generate a unique name (see Name Generation section below) if you didn't supply them — so in
practice the *hard* requirements are usually just `template`, and either an explicit `parent` or a
template whose `parentTemplates` narrows to exactly one possible parent.

## Deleting a page

```php
public function delete($recursive = false)  // Page.php line 2553, same as $pages->delete($this, $recursive)
public function trash()                     // Page.php line 2576, same as $pages->trash($this)
```

```php
public function ___delete(Page $page, $recursive = false, array $options = array())  // Pages.php line 1095
public function ___trash(Page $page, $save = true)                                    // Pages.php line 1120
public function ___restore(Page $page, $save = true)                                  // Pages.php line 1146
public function ___emptyTrash(array $options = array())                               // Pages.php line 1174
```

### Permanent delete

```php
$product = $pages->get('/products/foo-bar-widget/');
$pages->delete($product);
```

`Pages::delete()` is permanent and **not** restorable, unlike `trash()`. The real logic is in
`PagesEditor::delete()` (`PagesEditor.php` line 1269):

- Throws `WireException` via `isDeleteable($page, true)` if the page is a `NullPage`, has no `id`,
  has `statusSystemID`/`statusSystem`, has `statusLocked`, or **is the page currently being
  viewed** (`$page->id === $this->wire()->page->id`) — in that last case the exception message
  itself suggests: `"try $pages->trash() instead"` (`PagesEditor.php` line 336; see
  `isDeleteable()` at line 323).
- If the page has children (`$page->numChildren`) and `$recursive` is not `true`, it throws:
  `"Can't delete Page $page because it has one or more children."` (`PagesEditor.php` line 1305).
  With `$recursive = true`, children are deleted first, depth-first, via recursive calls to
  `$this->pages->delete($child, true, $options)`.
- On success it fires `deleteReady`/`deleted` hooks, physically `DELETE FROM pages WHERE id=...`,
  removes sortfield tracking, and sets the in-memory page's `status` to `Page::statusDeleted`
  (runtime-only status, not stored — see Status Constants below).
- Return value: `true` on a simple (non-recursive) success, or an **integer count of pages
  deleted** when `$recursive` is used (`Page.php` doc: "or int quantity of pages deleted when
  recursive option is true").

```php
// Delete pages named "delete-me" that don't have children
foreach($pages->find("name=delete-me, numChildren=0") as $item) {
    $item->delete();
}

// Delete a page and recursively all of its children, grandchildren, etc.
$item = $pages->get('/some-page/');
$item->delete(true);
```

### Trash (soft delete / recoverable)

```php
$product = $pages->get('/products/foo-bar-widget/');
$pages->trash($product);
```

`Pages::trash()` delegates to `$this->trasher()->trash($page, $save)` (`Pages.php` line 1120-1123);
implementation lives in the `PagesTrash` helper (not in the files read for this reference — see
`$pages->trasher()`, `Pages.php` line 2158, if deeper trash-mechanics detail is needed). Trashed
pages are in a "delete pending" state: they can be restored via `$pages->restore($page)` to their
original location (or a new location if you set `$page->parent` first), or permanently removed via
`$pages->emptyTrash()`.

`Page::isTrash()` (`Page.php` line 3573) checks `hasStatus(Page::statusTrash)`, whether the page's
own ID equals `$config->trashPageID`, or whether any of its parents is the trash page — this means
`isTrash()` correctly reports `true` even immediately after a trash operation, before the page has
been re-saved.

## Status / state methods

```php
public function isNew()          // Page.php line 3544 — true if the page has not yet been saved to DB
public function isLoaded($fieldName = null)  // Page.php line 3557 — is page (or one field) fully loaded
public function isChanged($what = '')        // Page.php line 2679 — true if isNew(), or any tracked/nested change exists
public function isTrash()        // Page.php line 3573
public function isHidden()       // Page.php line 3508 — hasStatus(statusHidden)
public function isUnpublished()  // Page.php line 3520 — hasStatus(statusUnpublished)
public function isLocked()       // Page.php line 3532 — hasStatus(statusLocked)
public function isPublic()       // Page.php line 3594 (hookable via ___isPublic) — published AND guest-viewable
```

`isChanged()` returns `true` immediately for any brand-new (unsaved) page, regardless of whether
you've set anything yet (`Page.php` line 2680: `if($this->isNew()) return true;`). It otherwise
checks the base `Wire::isChanged()` tracked-changes list, then also recurses into any `Wire`-typed
field values still in `$this->data` to see if *they* report internal changes (e.g. a `PageArray`
value with added/removed items) — `Page.php` lines 2679-2693.

### Status flags (`Page::status*` constants, `Page.php` lines 226-348)

| Constant | Value | Notes |
|---|---|---|
| `statusOn` | 1 | internal boolean-true flag bit |
| `statusReserved` | 2 | reserved, internal |
| `statusLocked` | 4 | page locked for changes (name: `locked`) |
| `statusSystemID` | 8 | system page; ID/deletion protected (name: `system-id`) |
| `statusSystem` | 16 | system page; ID/name/template/parent all protected (name: `system`) |
| `statusUnique` | 32 | page has a globally unique name |
| `statusDraft` | 64 | pending draft changes (name: `draft`) |
| `statusFlagged` / `statusIncomplete` | 128 | flagged/incomplete (alias); `statusVersions` (128) is a deprecated unused alias of the same value |
| `statusInternal` | 256 | reserved, internal |
| `statusTemp` | 512 | temporary; combined with `statusUnpublished`, 1+ day old pages may be auto-deleted |
| `statusHidden` | 1024 | excluded from page-finding methods unless overridden |
| `statusUnpublished` | 2048 | not publicly visible; excluded from find unless overridden |
| `statusTrash` | 8192 | page is in the trash |
| `statusDeleted` | 16384 | runtime-only; page was just deleted, not stored in DB |
| `statusSystemOverride` | 32768 | runtime-only; allows system flags to be overridden |
| `statusCorrupted` | 131072 | runtime-only; page has a field that would corrupt on save (see output-formatting gotcha) |
| `statusMax` | 9999999 | for comparisons only, never assign |

Per the in-code comment (`Page.php` lines 210-217): statuses `1024` and above are excluded from
search by the core by default; statuses `16384` and above are runtime-only and never persisted
(except possibly to logs/page history).

```php
public function hasStatus($status)       // Page.php line 3379 — accepts int constant or string name ('hidden', 'locked', ...)
public function addStatus($statusFlag)   // Page.php line 3407
public function removeStatus($statusFlag)// Page.php line 3437 — throws if removing system/systemID without statusSystemOverride first
public function status($value = false, $status = null)  // Page.php line 3648 — combined getter/setter
```

```php
if($page->hasStatus('hidden')) { /* ... */ }
if($page->hasStatus(Page::statusHidden)) { /* ... */ }

$page->addStatus('hidden');
$page->addStatus(Page::statusHidden);
$page->removeStatus('hidden');

$status = $page->status();               // get bitmask
$names = $page->status(true);            // get array of status names
$page->status(Page::statusHidden | Page::statusUnpublished);  // set by bitmask
$page->status('unpublished');            // set by name
$page->status(['hidden', 'unpublished']);// set by names array
```

String status names are resolved via `PageProperties::$statuses` (`PageProperties.php` lines
38-56), e.g. `'hidden' => Page::statusHidden`.

### `meta()` — free-form persistent metadata independent of fields/save

```php
public function meta($key = '', $value = null)  // Page.php line 4262
```

`meta()` stores/reads arbitrary key/value data in the `pages_meta` DB table via a `WireDataDB`
instance, entirely independent of the normal page load/save cycle — a `set` immediately writes to
the DB and a `get` immediately reads it (`Page.php` lines 4220-4224 doc comment). Values must be
basic PHP types (arrays, strings, numbers) — not objects.

```php
$page->meta()->set('colors', ['red', 'green', 'blue']);
$colors = $page->meta()->get('colors');

// shorter syntax
$page->meta('colors', ['red', 'green', 'blue']); // set
$colors = $page->meta('colors');                  // get

$page->meta()->remove('colors');
$values = $page->meta()->getArray();              // all meta values
```

## Permissions: `viewable()`, `editable()`, `addable()`, etc.

These are **not** defined as real methods on `Page` or in `PageAccess.php`. `PageAccess.php`
(`wire/core/PageAccess.php`) only provides `getAccessParent()`, `getAccessTemplate()`,
`getAccessRoles()`, `hasAccessRole()` — the plumbing for figuring out *which* template/roles
govern access to a page.

The actual `$page->viewable()`, `->editable()`, `->addable()`, `->deleteable()`/`->deletable()`,
`->trashable()`, `->restorable()`, `->moveable()`, `->sortable()`, `->publishable()`,
`->cloneable()`, `->listable()` methods are added at runtime as **hooks** by the always-autoloaded
`PagePermissions` module (`wire/modules/PagePermissions.module`), registered in its `init()`:

```php
$this->addHook('Page::editable', $this, 'editable');
$this->addHook('Page::viewable', $this, 'viewable');
$this->addHook('Page::addable', $this, 'addable');
// ...etc (PagePermissions.module init(), lines 108-124)
```

```php
if($page->editable()) { /* current user can edit */ }
if(!$page->viewable()) { /* current user cannot view */ }
if($page->addable()) { /* current user can add children here */ }
```

Key behaviors worth knowing (from `PagePermissions.module`):

- `editable($field = null)` optionally accepts a field name to also check field-level edit access;
  superusers always pass; system pages (`statusSystem`, except `language` template) are never
  editable via this check; locked pages require `page-lock` permission; unpublished pages bypass
  the optional `page-publish` permission requirement.
- `deleteable()`/`deletable()` (both hooked to the same handler) return `false` for locked pages,
  and otherwise require `pages->isDeleteable($page)` plus `page-delete` permission (unless
  superuser).
- `trashable()` returns `false` if the page is already trashed or its template has `noTrash` set
  (unless you pass `true` as the first arg, treated as "deleteable OR trashable").
  `restorable()` checks the page is actually in the trash, is editable, and that the resolved
  restore-parent is `addable()`.
  `moveable($parent = null)` checks `editable('parent')`; if a `$parent` is given it also checks
  `$parent->addable($page)`.
  `cloneable($recursive = null)` requires `page-create` permission on the page's parent context,
  and (if the page has children) requires `page-clone-tree` permission for a recursive clone.

## Creating pages: field-value default population

`PagesEditor::setupNew()` (`PagesEditor.php` lines 361-408) runs automatically the first time a
new page is saved. Besides assigning a parent/name/sort if missing, it iterates
`$page->template->fieldgroup` and, for each field the page doesn't already have a loaded value for,
populates the Fieldtype's configured `defaultValue` (if any) via
`$field->type->getDefaultValue($page, $field)`.

## Automatic page-name generation

`PagesNames.php` implements the "auto name" behavior referenced above.
`setupNewPageName(Page $page, $format = '')` (`PagesNames.php` line 94) is the entry point (called
via `$pages->setupPageName($page)` from `PagesEditor::setupNew()`), and:

1. If the page already has a non-"untitled" name, it does nothing (returns blank).
2. Otherwise determines a format via `defaultPageNameFormat()` (`PagesNames.php` line 217): prefers
   the parent template's `childNameFormat` setting; falls back to `'title'` if the page has a
   title; falls back further to `'untitled-time'`.
3. Builds the candidate name via `pageNameFromFormat()` (`PagesNames.php` line 290), which supports
   format strings like `title`, any other field name, `{field}` / `{a|b|c}` placeholder syntax,
   `random`, `untitled`, `untitled-time`, and `date:Y-m-d-H-i` style date formats.
4. Ensures uniqueness via `uniquePageName()` (`PagesNames.php` line 443) — colliding names get an
   incrementing numeric suffix, e.g. `foo` → `foo-1` → `foo-2`.

`PagesEditor::save()` also independently calls `$this->pages->names()->checkNameConflicts($page)`
before every save (unless `adjustName` option is `false` or the page already has `_hasUniqueName`
set) — so name-uniqueness-within-parent is enforced on every save, not just page creation
(`PagesEditor.php` lines 512-514).

## Cloning a page

```php
public function ___clone(Page $page, ?Page $parent = null, $recursive = true, $options = array())  // Pages.php line 1065
```

```php
$building = $pages->get('/skyscrapers/atlanta/westin-peachtree/');
$copy = $pages->clone($building);

$copy->parent = '/skyscrapers/detroit/';
$copy->title = 'Renaissance Center';
$copy->name = 'renaissance-center';
$copy->save();
```

Implementation is `PagesEditor::_clone()` (`PagesEditor.php` line 1374). It clones the page object
in memory (`clone $page`), resets `isNew`, assigns a unique name (or uses `options['set']['name']`
if given), copies file assets on disk, and — by default (`$recursive = true`) — recursively clones
all children too, in batches of 200. `$options['forceID']` lets you force a specific new ID;
`$options['set']` lets you pre-set properties on the clone before its first save. Returns a
`NullPage` (id=0) on failure rather than throwing in most cases (though the underlying `save()`
call inside can still throw).

## `Pages::touch()` — updating modification time without a full save

```php
public function ___touch($pages, $options = null, $type = 'modified')  // Pages.php line 1509
```

```php
$pages->touch($page);                                  // set modified to now
$pages->touch($page, '2016-10-24 00:00');               // set modified to a specific date
$pages->touch($pages->find('template=skyscraper'));     // touch a whole PageArray at once
```

Accepts a single `Page`, a `PageArray`, or an array of page IDs. `$options` can be a
timestamp/date-string, or an array with `time`, `type` (`'modified'|'created'|'published'`), and
`user` (bool or `User` to also update the created/modified user) keys.

## Loading and caching (`PagesLoader.php`, `PagesLoaderCache.php`)

`Pages::getById()` (`Pages.php` line 1241) delegates to `PagesLoader::getById()` and is described
as the path "all pages loaded by ProcessWire pass through." Relevant load options (from the doc
block, `Pages.php` lines 1187-1202) include `cache` (place loaded pages in the in-memory cache,
default `true`), `getFromCache` (allow reuse of a previously-cached instance, default `true`),
`autojoin`, `joinFields`, `pageClass`, and `getOne` (return a single `Page` rather than a
`PageArray`). `Page::$loaderCache` (settable per-page, `Page.php` line 505 / `get`/`set` case
`'loaderCache'`) controls whether pages loaded *as a result of* this page (e.g. its lazy-loaded
relations) are eligible for that memory cache.

## Traversal quick reference

From `PageTraversal.php`, surfaced on `Page` as:

```php
$page->children($selector = '', $options = array());  // Page.php line 1887
$page->child($selector = '');                          // Page.php ~1974, first matching child
$page->parent($selector = '');                          // Page.php line 2000
$page->parents($selector = '');                         // Page.php line 2044
$page->siblings($selector = '', $includeCurrent = true); // Page.php line 2147
$page->next($selector = '');  $page->prev($selector = '');
$page->find($selector = '', $options = array());        // Page.php line 1817 — like Pages::find() scoped to descendants
$page->findOne($selector = '', $options = array());      // Page.php line 1849
```

`Page::find()`/`findOne()` automatically prepend `has_parent={$this->id}` to your selector
(`Page.php` lines 1817-1826) and short-circuit to an empty `PageArray`/`NullPage` if
`$this->numChildren` is `0` — so calling `find()` on a childless page never hits the database.

By default, `children()` (like most `PageArray`-returning traversal) excludes hidden, unpublished,
and no-access pages unless you add `include=hidden|unpublished|all` to your selector (per the
`Page::children()` doc block, `Page.php` lines 1861-1865).

## `PageArray` — what `find()`/`children()` etc. return

`PageArray` (`wire/core/PageArray.php`) is a `WireArray`-derived collection of `Page` objects. It
adds page-specific helpers on top of the base `WireArray` API, notably:

```php
$pageArray->getPageByID($id);
$pageArray->getPageByName($name);
$pageArray->getPageByProperty($property, $value, $strict = false);
$pageArray->find($selector);      // in-memory filter, no DB query
$pageArray->findOne($selector);
$pageArray->filter($selector);    // narrow the array in-memory
$pageArray->not($selector);       // inverse filter
$pageArray->slice($start, $limit = 0);
$pageArray->eq($num);
$pageArray->first();  $pageArray->last();
```

## Comparison methods (`PageComparison.php`)

```php
public function is($status)             // Page.php line 3493 — status/template-name/selector check
public function matches($s)             // Page.php line 3456 — in-memory selector match (WireMatchable interface)
public function matchesDatabase($s)     // Page.php line 3477 (3.0.225+) — same but queries the DB
```

```php
if($page->matches("created>=" . strtotime('today'))) { /* created today */ }
```

## Multi-language notes (as visible in core, without the LanguageSupport module itself)

`Page::setName($value, $language = null)` (`Page.php` line 1627) accepts an optional language
argument (`Language` object, name, or int ID) to set a page's name for a specific language — this
only does something meaningful when `LanguageSupportPageNames` is installed, at which point
`PageProperties::$languageProperties` gets populated with entries like `'name1234' => ['name',
1234]` (`PageProperties.php` lines 216-231), enabling `$page->name1234 = '...'` style direct
property access for per-language names/status. `PagePermissions.module`'s `pageEditable()` and
`fieldEditable()` also reference several optional multi-language permissions
(`page-edit-lang-default`, `page-edit-lang-none`, `page-edit-lang-[language]`) that, when
installed, gate editing of default-language vs. non-multi-language vs. specific-language field
values — see the large doc comment at the top of `PagePermissions.module` (lines 15-56) for the
full list of these optional permissions and what each does.

## Summary of common gotchas found in the code

1. **`$page->fields` is the page's `Fieldgroup`, not the `$fields` API variable.**
   (`PageProperties.php` line 169, `Page.php` line 46 doc comment.)
2. **Output formatting must be off before editing+saving.** Setting a value that would come out
   differently once formatted, while `of()` is true, sets `Page::statusCorrupted` and blocks
   `save()` (`PageValues.php` lines 1095-1116; `PagesEditor.php` lines 175-223).
3. **A template must be assigned before setting custom field values** — throws (or degrades in
   debug mode) otherwise (`PageValues.php` lines 1021-1034).
4. **New pages are `isChanged() === true` unconditionally**, even before any field is set
   (`Page.php` line 2680).
5. **`delete()` refuses pages with children unless `$recursive = true`**, and refuses to delete the
   page currently being viewed (suggesting `trash()` instead) — (`PagesEditor.php` lines
   1303-1305, 335-336).
6. **`trash()` is recoverable, `delete()` is not.** Trashed pages can be restored via
   `$pages->restore()`; permanently deleted pages cannot.
7. **Page names are automatically de-duplicated within a parent on every save**, not just on
   creation, via `checkNameConflicts()` unless you disable `adjustName` in save options
   (`PagesEditor.php` lines 512-514).
8. **`$pages->add()` saves the page twice** internally — once right after title/name/status are
   set (so Fieldtypes that need a page ID, like file fields, have one), and again after the rest of
   `$values` are applied (`PagesEditor.php` lines 126-135). The `Page` object it returns is a
   freshly reloaded copy, not your original in-memory instance (`PagesEditor.php` lines 137-146).
9. **System pages (`statusSystem`/`statusSystemID`) have protected `id`/`name`/`template`/`parent`.**
   Attempting to change these throws `WireException` from `Page::set()` itself
   (`Page.php` lines 715-721), independent of any permission check.
10. **`removeStatus()` throws if you try to remove `statusSystem`/`statusSystemID`** without first
    adding the runtime-only `statusSystemOverride` status (`Page.php` line 3433 doc, enforced in
    `PageValues::removeStatus()`).
11. **`viewable()`/`editable()`/etc. only exist because `PagePermissions.module` hooks them onto
    `Page` at runtime.** They are not native `Page` or `PageAccess` methods — if that module were
    ever disabled (it's marked `permanent`), those calls would not resolve.
