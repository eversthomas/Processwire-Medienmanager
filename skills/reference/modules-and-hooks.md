# ProcessWire Module and Hook System

This reference documents ProcessWire's module architecture and hook mechanism, verified against the
ProcessWire core source (`wire/core/Module.php`, `ModuleConfig.php`, `ConfigurableModule.php`,
`Modules.php`, `ModulesLoader.php`, `ModulesInstaller.php`, `ModulesInfo.php`, `WireHooks.php`,
`Wire.php`, `HookEvent.php`, and example modules in `wire/modules/`).

## 1. Basic module class structure

Every module is a PHP class that implements the `Module` interface (`wire/core/Module.php`). The
interface itself declares almost nothing as hard-required in PHP terms — most "required" behavior is
enforced by convention and by what `Modules`/`ModulesLoader` looks for via `method_exists()` — but the
documented contract is:

1. The class must provide a way for ProcessWire to get information about it: a static `getModuleInfo()`
   method, or a `ModuleName.info.php` file, or a `ModuleName.info.json` file (see §2).
2. The class must provide a `className()` method. `Wire`-derived classes already have this, so in
   practice you never implement it yourself — you just extend `Wire` or `WireData`.
3. If the class has a `__construct()`, it must accept no required arguments.
4. If the module is configurable, it must also implement `ConfigurableModule` (see §5).

Minimal example, straight from the `Module.php` docblock:

```php
<?php namespace ProcessWire;

class HelloWorld extends WireData implements Module {
  // your class implementation
}
```

Modules are conventionally written as `namespace ProcessWire;` and should extend `WireData` (generic
case), or, if the module is a specialized type, the relevant base class so it inherits the correct
`singular`/`autoload` defaults and hookable infrastructure. Recognized base module types, per
`Module.php`'s docblock, include: `AdminTheme`, `Fieldtype`, `FileCompilerModule`, `FileValidatorModule`,
`Inputfield`, `ModuleJS`, `PageAction`, `Process`, `Textformatter`, `WireAction`, `WireMail`,
`WireSessionHandler`.

Real, minimal core example (`wire/modules/Textformatter/TextformatterStripTags.module`):

```php
class TextformatterStripTags extends Textformatter implements ConfigurableModule {

	protected $data = array('allowedTags' => '');

	public static function getModuleInfo() {
		return array(
			'title' => 'Strip Markup Tags',
			'version' => 100,
			'summary' => "Strips HTML/XHTML Markup Tags",
		);
	}

	public function format(&$str) {
		$str = strip_tags($str, $this->data['allowedTags']);
	}

	public function getModuleConfigInputfields(array $data) { /* ... */ }
	public function __get($key) { return isset($this->data[$key]) ? $this->data[$key] : null; }
	public function __set($key, $value) { $this->data[$key] = $value; }
}
```

There is also an internal-only interface `_Module` in `Module.php` that lists every possible module
method for IDE code-hinting purposes (`install()`, `uninstall()`, `upgrade()`, `getModuleInfo()`,
`init()`, `ready()`, `setConfigData()`, `isSingular()`, `isAutoload()`,
`getModuleConfigInputfields($data = null)`, `getModuleConfigArray()`). This is not an interface you
implement — modules only need `Module`, and `ConfigurableModule` if applicable.

## 2. Module information: getModuleInfo() vs .info.php vs .info.json

Source: `Module.php` docblock and `ModulesInfo.php` (`$infoTemplate`, `$moduleInfoVerboseKeys`).

ProcessWire supports **three** interchangeable ways to declare module info; use exactly one:

1. **Static `getModuleInfo()` method** in the module class:

```php
public static function getModuleInfo() {
	return array(
		'title' => 'Your Module Title',
		'version' => 1,
		'author' => 'Your Name',
		'summary' => 'Description of what this module does and who made it.',
		'href' => 'http://www.domain.com/info/about/this/module/',
		'autoload' => false, // true to auto-load at boot
		'requires' => array(
			'HelloWorld>=1.0.1',
			'PHP>=5.4.1',
			'ProcessWire>=2.4.1',
		),
		'installs' => array('Module1', 'Module2', 'Module3'),
	);
}
```

2. **`YourModuleClass.info.php`** file (sibling to the module's main file) that populates an `$info`
   array with the same keys.

3. **`YourModuleClass.info.json`** file containing a JSON object with the same keys. Real core example,
   `wire/modules/Process/ProcessPageLister/ProcessPageLister.info.json`:

```json
{
	"title": "Lister",
	"summary": "Admin tool for finding and listing pages by any property.",
	"version": 26,
	"author": "Ryan Cramer",
	"icon": "search",
	"singular": false,
	"permanent": true,
	"permission": "page-lister",
	"permissions": {
		"page-lister": "Use Page Lister"
	},
	"useNavJSON": true,
	"addFlag": 32
}
```

### Recognized info keys

Verified from `Module.php`'s docblock and `ModulesInfo.php::$infoTemplate` (the internal template
`Modules` normalizes all module info into):

Required (by convention, not enforced at the PHP type level):
- `title` (string) — the module's title.
- `version` (int|string) — version number/identifier.
- `summary` (string) — one-sentence description.

Optional, all verified in `ModulesInfo.php::$infoTemplate` / `Module.php`:
- `href` (string) — URL with more info about the module.
- `icon` (string) — Font Awesome icon name, without the `fa-` prefix.
- `requires` (array|string) — module class names (optionally with version operators, e.g.
  `"HelloWorld>=1.0.1"`) required to install this module. Special names `PHP` and `ProcessWire` can be
  used to require a PHP or core version, e.g. `"PHP>=5.6.0"`, `"ProcessWire>=2.4.1"`. May be a CSV
  string; `ModulesInfo.php` normalizes it into an array (and populates `requiresVersions`, an
  array keyed by module name to `[$operator, $version]`).
- `installs` (array|string) — module class names this module will install/uninstall for you (excludes
  them from normal dependency-driven auto install/uninstall; if your module doesn't actually
  install/uninstall them, ProcessWire does it automatically immediately after/before your module).
- `permanent` (bool) — core-only flag; when true, the module cannot be uninstalled.
- `permission` (string) — name of a permission a (non-superuser) user must have for ProcessWire to load
  the module. Note: ProcessWire will *not* auto-install this permission (use `permissions` for that).
- `permissions` (array) — permissions to auto install/uninstall with the module, in
  `array('permission-name' => 'Permission description')` format.
- `singular` (bool) — restrict to a single running instance (default: auto-detected from module type).
- `autoload` (bool|string|callable|int) — whether/when to load at boot (default `false`). See §3.
- `searchable` (string) — presence indicates the module implements `SearchableModule::search()`; value
  is the name search results are grouped/referenced under.
- `configurable` — normally auto-detected; internal/derived value, not something you set directly.
- `created`, `installed`, `namespace`, `file`, `core`, `versionStr`, `author` — populated/derived by
  ProcessWire itself; `author`, `summary`, `href`, `file`, `core`, `versionStr`, `permissions`,
  `searchable`, `page`, `license` are only included in **verbose** module info
  (`ModulesInfo.php::$moduleInfoVerboseKeys`).
- Process-module-specific extras noted in `ModulesInfo.php`: `nav` (array — navigation definition),
  `useNavJSON` (bool), `page` (array — admin page to auto-create for a Process module),
  `permissionMethod` (string|callable).

`ModulesInfo.php` also defines default "null replacement" values used when a key is `null`:
`autoload` → `false`, `singular` → `false`, `configurable` → `false`, `core` → `false`,
`installed` → `true`, `namespace` → `"\\ProcessWire\\"`.

## 3. init() vs ready()

Source: `Module.php` docblock, `ModulesLoader.php::initModule()` / `readyModule()` / `triggerInit()` /
`triggerReady()`.

- `__construct()` — called by PHP at instantiation time, **before** any config data is populated. Must
  take no required arguments. Good for setting default property values. The module may be instantiated
  for informational purposes only, so don't assume it will actually run.
- `init()` — called after `__construct()` and after config data has been populated, at the end of the
  boot process, before ProcessWire retrieves/renders a page. `ModulesLoader::initModule()` calls
  `$module->init()` only if the method exists (checked via `method_exists($module, 'init')`) and the
  `configOnly` option wasn't passed. Good place to attach hooks if you don't need `$page` yet.
- `ready()` — **only called for autoload modules.** Called once boot has fully completed and all API
  variables are ready, but before any page is rendered. `ModulesLoader::triggerReady()` iterates loaded
  modules and calls `readyModule()`, which calls `$module->ready()` only if
  `method_exists($module, 'ready')` and the module has the `flagsAutoload` flag. This is often the
  preferred place for autoload modules to add hooks, since the full API is guaranteed available.

Autoload details (`ModulesLoader::triggerInit()`):
- Non-autoload modules are only instantiated/init'd on demand via `$modules->get('YourModule')`.
- `autoload` can be `true`/`false`, a **selector string** (module autoloads only if the current request
  matches the selector, e.g. `'template=admin'`), a **callable** (autoloads if it returns true), or an
  **integer ≥ 2** (autoloads before other autoload modules in `/site/modules/`; higher runs earlier).
- If an autoload module `requires` another autoload module, `triggerInit()` queues it and retries
  (up to a recursion depth of 3 queue levels) so dependencies init first.
- After `init()`, if the module is singular, `ModulesLoader::initModule()` clears its stored config data
  (no longer needed since the instance is already configured), unless `clearSettings` option is false.

## 4. install() / uninstall() / upgrade()

Source: `Module.php` docblock, `Modules.php::___install()` / `___uninstall()`,
`ModulesInstaller.php::install()`.

These are **not** separate interfaces — they are plain methods you optionally add to your module class.
Per `Module.php`: *"If implemented, install() methods typically are defined hookable as
`public function ___install()`"* (same for `uninstall()` and `upgrade()`), i.e. using the
triple-underscore hookable convention (§8), though a plain non-hookable `install()` is also recognized.

`ModulesInstaller.php::install()` (verified around line 162) checks for either form:

```php
if(method_exists($module, '___install') || method_exists($module, 'install')) {
	$module->install();
}
```

- `___install()` / `install()` — called by ProcessWire immediately after the module is added to the
  `modules` database table (called with the module's DB ID already assigned, so it's safe to install
  fields/pages/templates/permissions that reference the module's own ID). Throw a `WireException` if
  installation should fail — ProcessWire catches it, removes the module's DB row, and reports failure.
- `___uninstall()` / `uninstall()` — called right before the module's row is removed from the `modules`
  table. Should undo everything `install()` did. Throw `WireException` to abort uninstall.
- `___upgrade($fromVersion, $toVersion)` — called when ProcessWire detects the module's `version` in
  `getModuleInfo()` differs from the last known installed version. Receives old and new version.

After your module's own `install()` runs, `ModulesInstaller::install()` (verified in source) also:
- auto-installs any permissions declared in `getModuleInfo()['permissions']` that don't already exist;
- auto-installs any modules listed in `getModuleInfo()['installs']` that aren't installed yet (i.e. your
  module doesn't have to handle installing them itself unless you want full control).

Real core example, `wire/modules/PagePathHistory.module`:

```php
class PagePathHistory extends WireData implements Module, ConfigurableModule {

	public static function getModuleInfo() {
		return array(
			'title' => 'Page Path History',
			'version' => 8,
			'summary' => "Keeps track of past URLs where pages have lived...",
			'singular' => true,
			'autoload' => true,
		);
	}

	const dbTableName = 'page_path_history';

	public function ___install() {
		$database = $this->wire()->database;
		$len = $database->getMaxIndexLength();
		$table = self::dbTableName;
		if($database->tableExists($table)) { $this->checkTableSchema(); return; }
		$sql = "CREATE TABLE $table (" .
			"path VARCHAR($len) NOT NULL, " .
			"pages_id INT UNSIGNED NOT NULL, " .
			"language_id INT UNSIGNED DEFAULT 0, " .
			"created TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, " .
			"PRIMARY KEY path (path), " .
			"INDEX pages_id (pages_id), INDEX created (created)" .
			") ENGINE={$this->config->dbEngine} DEFAULT CHARSET={$this->config->dbCharset}";
		$database->query($sql);
	}

	public function ___uninstall() {
		$this->wire()->database->query("DROP TABLE " . self::dbTableName);
	}

	public function ___upgrade($fromVersion, $toVersion) {
		if($this->checkTableSchema()) {
			if($fromVersion != $toVersion) $this->message("PagePathHistory v$fromVersion => v$toVersion");
		}
	}
}
```

At the API level, module install/uninstall are triggered via `Modules`:

```php
$modules->install('YourModuleClass');
$modules->uninstall('YourModuleClass');
```

`Modules::___install()` and `Modules::___uninstall()` are themselves hookable (delegate to
`ModulesInstaller`), so you can hook into the module install/uninstall process globally, e.g.
`$modules->addHookAfter('install', ...)`.

## 5. ConfigurableModule interface

Source: `ConfigurableModule.php`, `ModuleConfig.php`.

`ConfigurableModule` (in `wire/core/ConfigurableModule.php`) is an interface that says a module can
receive/expose config values via `__get()`/`__set()`. It requires `Module` also be implemented:

```php
class Something extends WireData implements Module, ConfigurableModule { ... }
```

`WireData`-based modules already have working `__get()`/`__set()`, which is why the docs recommend
extending `WireData`.

To supply the actual config *fields*, implement **one** of:

1. **`getModuleConfigInputfields()`** — static or non-static, returns an `InputfieldWrapper`:
   ```php
   // static version — module info about it does NOT require instantiating the module
   public static function getModuleConfigInputfields(array $data) { ... return $inputfields; }

   // non-static version — has access to $this->something (API vars, other properties)
   public function getModuleConfigInputfields() { ... return $inputfields; }

   // non-static, convenience form: ProcessWire pre-builds $inputfields for you
   public function getModuleConfigInputfields($inputfields) { $inputfields->add(...); }
   ```
   Static pros: module needn't be instantiated (avoids unnecessary hook attachment/asset loading);
   works on all PW versions. Static con: can't read `$this`, must rely on the `$data` array (empty if
   never configured before). Non-static pros: direct access to `$this->property` and API vars, but
   requires PW ≥ 2.5.27 and instantiates the module.

2. **`getModuleConfigArray()`** — static or non-static, returns a plain array describing Inputfields
   (format matches `InputfieldWrapper::importArray()`). Use this *or* `getModuleConfigInputfields()`,
   not both — ProcessWire only recognizes one.

3. **A separate `ModuleNameConfig.php`** file containing a class named `ModuleNameConfig` that extends
   `ModuleConfig` (`wire/core/ModuleConfig.php`). Two ways to define fields in that class:

   - Programmatic (`InputfieldWrapper` construction) — implement `getInputfields()`, calling
     `parent::getInputfields()` first, and implement `getDefaults()` to return
     `array('propertyName' => defaultValue, ...)`.
   - Declarative array, via the constructor calling `$this->add(array(...))`:
     ```php
     class YourModuleConfig extends ModuleConfig {
         public function __construct() {
             parent::__construct();
             $this->add(array(
                 array('name' => 'fullname', 'type' => 'text', 'label' => 'Full Name', 'value' => ''),
                 array('name' => 'email', 'type' => 'email', 'label' => 'Email Address',
                       'placeholder' => 'you@company.com', 'value' => ''),
             ));
         }
     }
     ```
     `getDefaults()` is then auto-derived from the array (see `ModuleConfig::identifyDefaults()`).

Optional method: `setConfigData(array $data)` — if present, ProcessWire calls it with the whole config
array instead of populating properties individually via `__set()`.

`getModuleConfigInputfields($data = null)` on the internal `_Module` hint interface shows both
static/non-static call shapes exist; use whichever matches your implementation.

### ConfigModule (since 3.0.179)

A separate, related interface also in `ConfigurableModule.php`:

```php
interface ConfigModule {
	public function __get($key);
	public function __set($key, $value);
}
```

Use `ConfigModule` instead of `ConfigurableModule` when the module accepts config settings but is not
meant to be interactively configured through the admin UI — settings are only managed via
`$modules->saveConfig()` / `$modules->getConfig()` from code. A module must implement **either**
`ConfigModule` or `ConfigurableModule`, never both.

## 6. Hook syntax

Source: `Wire.php` (public wrapper methods) and `WireHooks.php` (`addHook()` internal implementation).

Every `Wire`-derived object (nearly everything in ProcessWire) exposes these public hook methods,
verified in `wire/core/Wire.php`:

```php
public function addHook($method, $toObject, $toMethod = null, $options = array())
public function addHookBefore($method, $toObject, $toMethod = null, $options = array())
public function addHookAfter($method, $toObject, $toMethod = null, $options = array())
public function addHookProperty($property, $toObject, $toMethod = null, $options = array())
public function addHookMethod($method, $toObject, $toMethod = null, $options = array())
public function removeHook($hookId)
public function hasHook($name)
```

Each of these delegates to `WireHooks::addHook(Wire $object, $method, $toObject, $toMethod = null,
$options = array())`, the real engine (`wire/core/WireHooks.php`, line ~563).

### Parameter shapes

- `$method` (string|array) — method to hook, **omitting** the three leading underscores:
  - `'method'` — hooks only the current object instance.
  - `'ClassName::method'` — hooks **all** instances of `ClassName`.
  - Since 3.0.137: may be an array or CSV string of multiple hook definitions, attached to the same
    handler.
  - Special forms supported by `WireHooks::addHook()`: `'Class::method(selectorString)'` to match
    against argument 0 (or `'Class::method(1:sel, 3:sel)'` for specific zero-based argument indexes);
    `'Class::method:<matchValue'`/`'Class::method:(selectorString)'` to match against the **return**
    value (only valid with `after` hooks — `before`-only with no `after` throws a `WireException`);
    `'Class::*'` or `'Class::save*'` wildcard hooks that expand to every matching hookable (`___`)
    method on the class.
- `$toObject` — one of: an object instance to call `$toMethod` on (e.g. `$this`); a closure; a
  procedural function name; or `null` if `$toMethod` supplies the callable/closure/function name
  directly (2-argument call form).
- `$toMethod` — method name on `$toObject`, a function name, or (if `$toObject` is omitted) the
  `$options` array itself.
- `$options` (array) — merged over `WireHooks::$defaultHookOptions`:
  ```php
  array(
      'type' => 'method',      // 'method' | 'property' | 'either'
      'before' => false,
      'after' => true,
      'priority' => 100,        // lower runs first; supports "100.1" style sub-priorities for ties
      'allInstances' => false,  // auto-set true when using 'Class::method' form
      'fromClass' => '',        // auto-set from 'Class::method' form
      'argMatch' => null,
      'objMatch' => null,
      'retMatch' => null,
  )
  ```

### The four convenience methods

```php
// Runs before the hooked method executes — can modify arguments, or replace the method entirely
$this->addHookBefore('Page::path', $this, 'myBeforeHandler');

// Runs after the hooked method executes — can read/modify the return value
$this->addHookAfter('Pages::saved', function(HookEvent $event) {
	$page = $event->arguments(0);
	$event->message("You saved page $page->path");
});

// Adds a new readable property to every instance of a class
$wire->addHookProperty('Page::lastModifiedStr', function($event) {
	$page = $event->object;
	$event->return = wireDate('relative', $page->modified);
});
// echo $page->lastModifiedStr; // also callable as $page->lastModifiedStr()

// Adds an entirely new public method to a class (also becomes hookable itself)
$wire->addHookMethod('Page::myHasParent', function($event) {
	$page = $event->object;
	$parent = $event->arguments(0);
	$event->return = $page->parents()->has($parent);
});
```

`addHookBefore()` is literally `addHook()` with `$options['before'] = true` (and `after` defaulted to
false unless explicitly set); `addHookAfter()` sets `$options['after'] = true`. `addHookProperty()` sets
`$options['type'] = 'property'`. `addHookMethod()` (added 3.0.16) is a plain alias for `addHook()` for
naming clarity — no behavioral difference.

### removeHook()

```php
$hookID = $pages->addHookAfter('find', function($event) { /* ... */ });
$pages->removeHook($hookID);

// Or, from inside the hook itself, remove the currently-running hook:
$hookID = $pages->addHookAfter('find', function($event) {
	// do something
	$event->removeHook(null); // note: called on $event, not $pages
});
```

`removeHook()` accepts a single hook ID, or (since 3.0.137) an array or CSV string of multiple IDs (the
multi-method `addHookX()` calls return a CSV string of IDs you can pass straight back in).

### Guard: hooking an existing, non-hookable method fails

`WireHooks::addHook()` throws if you try to hook a name that already exists as a normal (non-`___`)
method:

```php
if(method_exists($object, $method)) {
	throw new WireException("Method " . $object->className() . "::$method is not hookable");
}
```

Only methods actually defined with the `___` prefix (or names that don't exist at all, for
`addHookProperty`/`addHookMethod` additions) can be hooked.

## 7. The HookEvent ($event) object

Source: `wire/core/HookEvent.php`.

Hook handler functions/methods receive a single `HookEvent $event` argument (extends `WireData`).
Documented properties (from the class docblock):

- `$event->object` (read-only) — the `Wire`/`WireData`/`WireArray`/`Module` instance the hook fired on.
- `$event->method` (read-only) — the name of the method that triggered the hook event.
- `$event->arguments` — numerically indexed array of arguments passed to the hooked method. Prefer the
  `arguments()` method over direct access (see below).
- `$event->return` — applies to `after` hooks (and `before` hooks combined with `replace`): the value
  that will be returned by the call. Your hook can read and overwrite it.
- `$event->replace` — set `true` in a **before** hook to prevent the original hooked method body from
  running at all — your hook fully replaces it. Documented as "not recommended, so be careful."
- `$event->options` — the options array passed to the hook when it was registered, plus all default hook
  properties; custom user data goes through `$event->data`.
- `$event->id` (read-only) — the hook ID, usable with `removeHook()`.
- `$event->when` (read-only) — `'before'` or `'after'`, indicating which phase is currently executing.
- `$event->cancelHooks` — set `true` to cancel all remaining hooks for this call (use carefully).

### Reading/writing arguments

Verified signature:

```php
public function arguments($n = null, $value = null)
```

```php
$page = $event->arguments(0);           // get arg by zero-based index
$arguments = $event->arguments();        // get array of all arguments
$page = $event->arguments('page');       // get arg by its parameter name (reflection-derived)
$event->arguments(0, $page);             // set arg 0 (only meaningful in 'before' hooks)
$event->arguments('page', $page);        // set arg by name
```

Argument names are resolved via `ReflectionMethod` against the class's `___method()` signature
(`HookEvent::getArgumentNames()`), and cached per `ClassName.method`. There's also
`argumentsByName($n = '')` (returns an associative array of all args by name, or a single named value) —
`$event->arguments('name')` is documented as a shorthand for this.

### Example: before hook replacing return value entirely

```php
$wire->addHookBefore('Page::title', function(HookEvent $event) {
	$event->replace = true;
	$event->return = 'Custom Title';
});
```

### Example: after hook modifying return value

```php
$pages->addHookAfter('find', function(HookEvent $event) {
	$matches = $event->return; // a PageArray
	$event->return = $matches->sort('-created');
});
```

## 8. The `___` (triple-underscore) hookable method convention

Source: `Wire.php::__call()` (lines ~446-486), `Wire.php::_callHookMethod()`, `WireHooks.php` docblocks.

A method becomes hookable by prefixing its real implementation with three underscores, e.g.:

```php
public function ___save(Page $page, $options = array()) { /* real implementation */ }
```

Real, verified core examples:
- `Pages::___find($selector, $options = array())` — `wire/core/Pages.php`
- `Pages::___save(Page $page, $options = array())` — `wire/core/Pages.php`
- `Page::___render($options = [], $options2 = null)` — `wire/core/Page.php`
- `PagePathHistory::___install()`, `___uninstall()`, `___upgrade()` — see §4.

**Mechanism** (from `Wire::__call()`): the public-facing name (`save`, `find`, `render`, `install`, ...)
does **not** exist as a real method. When calling code does `$pages->save($page)`, PHP can't find a
`save()` method, so PHP's magic `__call()` fires. `Wire::__call()`:

1. Asks `WireHooks::runHooks($this, $method, $arguments)` to run any registered hooks for that method
   name (executing `before` hooks first).
2. `runHooks()` internally invokes the real `___save()` implementation (unless a `before` hook set
   `$event->replace = true`), sandwiched between `before` and `after` hooks.
3. Returns the final (possibly hook-modified) return value.

So calling `$pages->save($page)` from anywhere in the API transparently becomes: run `before` hooks →
run `___save()` (unless replaced) → run `after` hooks → return `$event->return`. This is why *any*
`___`-prefixed method in the core (or in your own `Wire`/`WireData` subclasses) can be intercepted with
`addHookBefore()`/`addHookAfter()` using just the public name (`save`, not `___save`).

To make your own module methods hookable, simply prefix your method definition with `___` and call it
elsewhere (in your own code or the public API) using the un-prefixed name — the same magic `__call()`
mechanism on `Wire` handles the redirection automatically, since your module presumably extends `Wire`
or `WireData`.

`Wire::_callHookMethod($method, array $arguments = array())` (internal optimization helper) shows the
same fallback logic explicitly: if a hooked or unhooked call reaches it, and no plain `$method` exists,
it calls `$this->_callMethod("___$method", $arguments)` directly.

## 9. Gotchas and quirks (found in code/comments)

- **You cannot hook a method that already exists under its plain (non-`___`) name.**
  `WireHooks::addHook()` throws `WireException("Method X::y is not hookable")` if
  `method_exists($object, $method)` is true for the plain name — only `___`-prefixed methods (or
  entirely new names, for `addHookProperty`/`addHookMethod`) qualify.

- **Priority ties get sub-priorities automatically.** Internally, `WireHooks::addHook()` turns an
  integer priority like `100` into `"100.0"`, and if another hook is already registered at that exact
  priority for the same method, it appends `.1`, `.2`, etc. so ordering stays deterministic — you don't
  need to manage this yourself, but it explains why hook IDs sometimes look like `":100.1:save"`.

- **You can target a specific object instance's return value.** `'Class::method:(selectorString)'` or
  `'Class::method:<value'` syntax matches the *return value* of a call before running the hook — but
  this only works for `after` hooks; `WireHooks::addHook()` throws
  `WireException('You cannot match return values with "before" hooks')` if you combine `before => true`
  with `after => false` and a `retMatch`.

- **Argument-matching hooks via selector syntax embedded in the method string.**
  `addHook('Pages::save(template=product)')` (or `'0:template=product'`/`'1:selectorString'` for
  specific argument indexes) restricts the hook to firing only when the given argument matches a
  ProcessWire selector — verified in `WireHooks::addHook()`'s parsing of `(`/`)`/`:` in the method
  string.

- **Wildcard hooks.** `addHook('Pages::*')` hooks *every* hookable (`___`-prefixed) method on `Pages`;
  `addHook('Pages::save*')` hooks every hookable method whose name starts with `save` (`save`,
  `saveReady`, `saved`, etc.) — implemented via `ReflectionClass::getMethods()` filtering for `___`
  prefixes in `WireHooks::addHook()`.

- **`autoload` is not just a boolean.** Per `Module.php` and `ModulesLoader::triggerInit()`, `autoload`
  may be `true`/`false`, a selector string (conditionally autoloads only when the current request/page
  matches, e.g. `'template=admin'`), a callable, or an integer ≥ 2 to control load order relative to
  other autoload modules (higher loads earlier). Non-boolean autoload values are resolved by
  `$this->modules->isAutoload($module)` at init time.

- **`ready()` only fires for autoload modules.** Even if a non-autoload module defines `ready()`, it
  will never be called unless the module is also flagged autoload — confirmed by
  `ModulesLoader::triggerReady()`'s check on `Modules::flagsAutoload`.

- **Config data is cleared after init for singular modules.** `ModulesLoader::initModule()` explicitly
  discards stored module config data after calling `init()` for autoload+singular modules (to save
  memory), unless the `clearSettings` option is passed as `false`.

- **`install()`/`uninstall()` accept both hookable and plain forms.** `ModulesInstaller::install()`
  checks `method_exists($module, '___install') || method_exists($module, 'install')` — so a plain
  non-hookable `install()` method also works, though the documented convention (and all core examples
  found) is the hookable `___install()` form.

- **`installs` vs actually calling `$modules->install()` yourself.** If your `getModuleInfo()['installs']`
  lists other module class names, ProcessWire will only auto-install them for you if your own
  `install()` didn't already do so — verified in `ModulesInstaller.php`'s post-install loop over
  `$info['installs']`, calling `$this->modules->install($name, ...)` for any not-yet-installed entries.

- **Module file naming affects discovery.** `ModulesFiles.php` shows ProcessWire looks for module files
  named `ModuleName.module` or `ModuleName.module.php`, either directly in a modules directory or inside
  a same-named subdirectory (`ModuleName/ModuleName.module`) — this is how ProcessWire finds modules to
  scan for `getModuleInfo()`/`.info.json`/`.info.php` without instantiating every class up front.

- **`ConfigurableModule` requires `Module` too.** Per `ConfigurableModule.php`'s docblock: *"When you use
  this as an interface, you MUST also use `Module` as an interface, i.e.
  `class Something implements Module, ConfigurableModule`."*

- **Never use both `getModuleConfigInputfields()` and `getModuleConfigArray()`,** and never mix static
  and non-static forms of the same method — `ConfigurableModule.php` states ProcessWire "will only
  recognize one or the other."

- **Never implement both `ConfigModule` and `ConfigurableModule`** on the same class —
  `ConfigurableModule.php` explicitly says to choose just one.

## Quick reference: minimal autoload hook-attaching module

Composited from verified patterns above (`Module.php`, `Wire.php`, `WireHooks.php`):

```php
<?php namespace ProcessWire;

class HelloHooks extends WireData implements Module {

	public static function getModuleInfo() {
		return array(
			'title' => 'Hello Hooks',
			'version' => 1,
			'summary' => 'Demonstrates attaching a hook from an autoload module.',
			'autoload' => true,
			'singular' => true,
		);
	}

	public function ready() {
		// Attach after ProcessWire's API is fully available
		$this->addHookAfter('Pages::saved', $this, 'onPageSaved');
	}

	protected function onPageSaved(HookEvent $event) {
		/** @var Page $page */
		$page = $event->arguments(0);
		$this->message("Page saved: {$page->path}");
	}

	public function ___install() {
		// e.g. create fields/templates/permissions here
	}

	public function ___uninstall() {
		// undo whatever install() did
	}
}
```
