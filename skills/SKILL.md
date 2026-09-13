---
name: processwire-core
description: Read this skill whenever writing, reviewing, or reasoning about ProcessWire CMS/CMF code — templates, modules, Fieldtypes/Inputfields, admin/backend logic, selectors, hooks, or field/page data access. Provides verified, project-independent ProcessWire API knowledge sourced from the actual core code.
---

# ProcessWire Core API Reference

**Analyzed against:** ProcessWire core **3.0.257 (dev)**, read directly from `wire/core/` and
`wire/modules/` of a real installation. Every claim in the `reference/` files below is traceable
to specific core source files (and, where useful, approximate line numbers) — not recalled from
training data. If ProcessWire has moved on to a materially newer major/minor version by the time
you're reading this, treat method names/signatures as "verified as of 3.0.257" and spot-check
anything load-bearing against the current core before relying on it.

This skill contains **no reference to any specific project** — it is general ProcessWire
knowledge, safe to reuse across any ProcessWire codebase.

## Core principles

- **Everything is a `Page`.** Content, users, admin templates, even some config structures are
  `Page` objects with a `template` that defines their fields. There's no separate "content type"
  system distinct from pages+templates.
- **Fields are typed by a `Fieldtype` module**, not by a fixed schema. A `Field` (the definition)
  references a `Fieldtype` (the type-behavior class) which knows how to sanitize, load ("wakeup"),
  save ("sleep"), and render its own values. Custom field types are added by writing a `Fieldtype`
  module, not by extending a core enum.
- **Almost every method is hookable.** Core methods prefixed with three underscores
  (`___methodName`) are dispatched through `Wire::__call()` so external code can hook before/after
  them, or replace them entirely, without modifying core files. This is the primary extension
  mechanism, alongside modules.
- **Selectors are ProcessWire's query language.** A compact, comma-separated string syntax
  (`"template=basic-page, status=1, sort=-created"`) is parsed by `Selectors`/`Selector` and,
  against the page database, compiled to SQL by `PageFinder`. The same syntax also filters
  in-memory `PageArray`/`WireArray` collections.
- **Sanitization and validation are different layers.** `$sanitizer` methods coerce input into a
  safe, usable value (never throwing); they are not the same as "required field" validation, which
  in the core happens at the `Inputfield`/form-processing layer, not at `$page->save()` time. See
  `reference/sanitization-validation.md` for the precise mechanics — this is a common source of
  incorrect assumptions.
- **Output formatting is stateful per-`Page`.** A `Page` object can have output formatting on
  (default, for front-end templates — values are rendered/entity-encoded) or off (for
  editing/saving raw values). Reading a field's raw, unformatted value while output formatting is
  on for that page will not give you what you expect; see `reference/page-api.md`.

## Reference files

- [reference/page-api.md](reference/page-api.md) — Reading and writing page fields (`get()`/`set()`/`save()`),
  output formatting, creating pages (`$pages->add()`/`new()`/`newPage()`), deleting vs. trashing,
  status constants, cloning, traversal, and permission checks — with the real method signatures
  and save-pipeline mechanics from `Page.php`, `Pages.php`, and `PagesEditor.php`.
- [reference/fields-and-fieldtypes.md](reference/fields-and-fieldtypes.md) — How a `Field` stores its
  configuration (native vs. dynamic properties), the `Fieldtype` base class's sanitize/wakeup/sleep
  lifecycle, and the actual configuration properties (`required`, `extensions`, `maxFiles`,
  `template_id`, select options, etc.) of the core Fieldtype modules (Text, Image/File, Page
  Reference, Options, Repeater, and more).
- [reference/selectors.md](reference/selectors.md) — Full selector syntax reference: the complete
  operator table (verified from every `Selector` subclass), OR-values and OR-groups, reserved
  properties (`sort`, `limit`, `start`, `include`, `check_access`, ...), Page Reference
  dot-notation sub-selectors, sorting, and pagination.
- [reference/modules-and-hooks.md](reference/modules-and-hooks.md) — Module class structure,
  `getModuleInfo()`/`.info.json`/`.info.php`, `init()`/`ready()` timing, `install()`/`uninstall()`,
  `ConfigurableModule`, and the hook system (`addHookBefore`/`addHookAfter`/`addHookProperty`/
  `addHookMethod`, the `HookEvent` object, and the `___method` convention) — all with real core
  examples.
- [reference/sanitization-validation.md](reference/sanitization-validation.md) — The `$sanitizer`
  API's method catalog (text, name/slug, numeric, array, and special-purpose sanitizers) with real
  signatures, how `Fieldtype::sanitizeValue()` fits into the save pipeline, and precisely where
  "required" field validation is actually enforced in the core.
