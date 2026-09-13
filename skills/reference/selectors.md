# ProcessWire Selectors

Selectors are ProcessWire's query language: short, comma-separated strings used with `$pages->find()`, `$pages->get()`, `$pages->count()`, and the same syntax used against `PageArray` and other `WireArray`-derived collections via `->find()`. They are parsed by `Selectors` (`wire/core/Selectors.php`) into a list of `Selector` objects (`wire/core/Selector.php`, base class plus one subclass per operator), and, when used against the database, executed by `PageFinder` (`wire/core/PageFinder.php`), which turns them into SQL.

This document was written by reading those three core files directly (version 3.0.257 dev). Every syntax rule below is traceable to the code cited alongside it.

## 1. Basic syntax

A selector string is one or more comma-separated statements of the form `field operator value`. All statements are AND'd together unless grouped otherwise (see OR-groups below).

```php
$pages->find("template=basic-page");
$pages->find("template=basic-page, status=1");
$pages->find("parent=/products/, price>100");
```

Source: `Selectors::extractString()` loops over the string, calling `extractField()`, `extractOperators()`, and `extractValue()` in turn, splitting on unescaped commas (`wire/core/Selectors.php`).

**Field-name-only existence checks.** Because `create()` requires an operator, a bare field name isn't itself valid — but ProcessWire's common idiom for "field is populated" is `field!=''` (not empty) or `field=''` (is empty), which works through the normal `=`/`!=` operators against a blank value:

```php
$pages->find("summary!=''");   // has a non-empty summary
$pages->find("summary=''");    // summary is empty/unset
```

**Multiple fields, one value (OR on field):** separate field names with `|`.

```php
$pages->find("title|body*=hello");   // title OR body contains "hello"
```

Source: `Selectors::extractField()` — a field string containing `|` is split into an array; `Selector::setField()` stores it as an array of OR'd field names (`wire/core/Selector.php`).

**Quoting values.** Values may be quoted in double quotes `"`, single quotes `'`, or (for special purposes described below) square brackets `[ ]`, braces `{ }`, or parentheses `( )`. Quoting is required whenever a value contains a comma, a pipe that should be literal (not an OR), spaces at the edges, or characters that would otherwise be parsed as selector syntax:

```php
$pages->find('title="Hello, World"');       // literal comma in value
$pages->find("body*='some text with | pipe'"); // literal pipe, not OR
```

Source: `Selectors::$quotes` defines the five opening→closing quote-character pairs; `extractValue()` handles quoted parsing, including escaped quotes (`\"`) and nested/embedded quotes (`wire/core/Selectors.php`).

Newlines in a selector string are silently converted to spaces before parsing (`extractString()`).

## 2. Operator table (verified from `Selector.php`)

Each operator is implemented by a `Selector` subclass whose static `getOperator()` method returns the exact symbol. All are registered via `Selector::loadSelectorTypes()`, which is called once at the bottom of `Selectors.php`.

| Operator | Class | Meaning |
|---|---|---|
| `=` | `SelectorEqual` | Equals |
| `!=` | `SelectorNotEqual` | Not equals |
| `>` | `SelectorGreaterThan` | Greater than |
| `<` | `SelectorLessThan` | Less than |
| `>=` | `SelectorGreaterThanEqual` | Greater than or equal |
| `<=` | `SelectorLessThanEqual` | Less than or equal |
| `*=` | `SelectorContains` | Contains phrase/word (fulltext, phrase match) |
| `*+=` | `SelectorContainsExpand` | Contains phrase, with fulltext query expansion |
| `%=` | `SelectorContainsLike` | Contains phrase, using SQL `LIKE` (not fulltext index) |
| `~=` | `SelectorContainsWords` | Contains all given words (whole words, any order) |
| `~*=` | `SelectorContainsWordsPartial` | Contains all given words, partial/starting-with match |
| `~%=` | `SelectorContainsWordsLike` | Contains all given words, `LIKE`-based partial match |
| `~~=` | `SelectorContainsWordsLive` | Contains all given words; last word may be partial (for "live search" as-you-type) |
| `~+=` | `SelectorContainsWordsExpand` | Contains all words, with fulltext query expansion |
| `~|=` | `SelectorContainsAnyWords` | Contains any of the given whole words |
| `~|*=` | `SelectorContainsAnyWordsPartial` | Contains any of the given words, partial/starting-with match |
| `~|%=` | `SelectorContainsAnyWordsLike` | Contains any of the given words, `LIKE`-based partial match |
| `~|+=` | `SelectorContainsAnyWordsExpand` | Contains any words, with query expansion |
| `**=` | `SelectorContainsMatch` | Native MySQL `MATCH/AGAINST` (implies DB relevance-score ordering) |
| `**+=` | `SelectorContainsMatchExpand` | Same as `**=` with query expansion |
| `#=` | `SelectorContainsAdvanced` | Advanced text search with `+required -disallowed "phrase"` command syntax (see below) |
| `^=` | `SelectorStarts` | Value starts with given phrase |
| `%^=` | `SelectorStartsLike` | Starts with, `LIKE`-based |
| `$=` | `SelectorEnds` | Value ends with given phrase |
| `%$=` | `SelectorEndsLike` | Ends with, `LIKE`-based |
| `&` | `SelectorBitwiseAnd` | Bitwise AND (used internally for `status`, also usable directly on integer/bitmask fields) |

Source: full class list read from `wire/core/Selector.php`, each class's `getOperator()` method.

**NOT prefix (`!`).** Any selector can be negated by prefixing the *field* with `!`, which reverses the match regardless of which operator is used, e.g. `!title^=Hello` means "title does NOT start with Hello". This is distinct from `!=` (the not-equal operator) and is handled by `Selectors::extractString()` detecting a leading `!` before the field, and by `Selector::setField()` detecting `!` prepended directly to a field name.

**`#=` advanced text search commands** (from the `SelectorContainsAdvanced` class doc block in `Selector.php`):
- `foo` — optional word (may appear)
- `+foo` — required word (must appear)
- `+foo*` — required, starts-with (wildcard) match
- `-bar` — disallowed word (must not appear)
- `-bar*` — disallowed, starts-with match
- `"foo bar baz"` — optional phrase (double quotes only, not single)
- `+"foo bar baz"` / `-"foo bar baz"` — required / disallowed phrase

```php
$pages->find('body#=+chocolate -nuts "gluten free"');
```

Use `$sanitizer->selectorValueAdvanced()` to sanitize user-supplied text destined for a `#=` operator (`wire/core/Sanitizer.php`) — it deliberately permits `+ - * ( ) "` since those are meaningful commands for this operator, converting stray double quotes into balanced parentheses.

**Compare-type flags.** Internally each operator class declares a bitmask via `getCompareType()` (`Selector::compareTypeExact`, `compareTypeSort`, `compareTypeFind`, `compareTypeLike`, `compareTypeBitwise`, `compareTypeExpand`, `compareTypeCommand`, `compareTypeDatabase`, `compareTypeFulltext`, `compareTypePhrase`, `compareTypeWords`, `compareTypePartial`, `compareTypeAny`, `compareTypeAll`, `compareTypeBoundary`) — these determine things like whether an operator requires a fulltext index, and are exposed via `Selectors::getOperators()` for introspection (`wire/core/Selectors.php`).

## 3. OR-value syntax within one field

Pipe-separate multiple values to match ANY of them:

```php
$pages->find("template=basic-page|contact-page");
$pages->find("status=hidden|unpublished");
```

Source: `Selectors::extractValue()` — after extracting a value, if the next character is `|`, it recurses to collect additional pipe-separated values into an array (`wire/core/Selectors.php`). Note: if the value is wrapped in `"double"` or `'single'` quotes, an embedded `|` is treated as a literal character, not an OR-separator (`extractValueQuick()`), so to search for a literal pipe you must quote the value.

Field-OR and value-OR can combine:

```php
$pages->find("title|body*=foo|bar"); // (title OR body) contains ("foo" OR "bar")
```

## 4. OR-groups across selectors — `(...)`

Wrapping part of a selector in parentheses creates a group. Two mechanisms exist, both driven by the `(` quote character:

**a) Unnamed groups — parenthesized statements separated by commas act as alternatives when they share the same (blank) group identity:**

```php
$pages->find("(a=1),(b=2)"); // matches if a=1 OR b=2
```

Source: `Selectors::extractField()` — when a selector segment starts with `(`, the field is left blank and `=(` is prepended so the parenthesized content is parsed as the value with quote type `(`. In `Selectors::matches()`, any selector with `quote === '('` is bucketed into `$orGroups` keyed by its (here blank) field name; entries sharing a key are OR'd together via `matchesOrGroups()`, and each distinct group key must have at least one match (i.e. different group *names* AND together, same group *name* OR together). `PageFinder::preProcessSelector()` implements the equivalent for database queries via `$this->extraOrSelectors[$groupName]`, defaulting unnamed groups to the key `'none'`, and combining separate sub-`PageFinder::find()` queries with `OR`/`AND` in `postProcessQuery()`.

**b) Field-repeated groups — the same field name used with `(...)` values multiple times is OR'd:**

```php
$pages->find("id>0, field=(status=1), field=(featured=1)"); // at least one 'field' condition must match
```

Here `field` is only a group label (per the comment in `PageFinder::postProcessQuery()`), not necessarily a real page field — the parenthesized content is itself a full selector string (a sub-selector) whose matching page IDs get OR'd in.

**Named groups via `name@`:** the `Selectors::extractGroup()` method also supports an explicit group name prefix using `@`, e.g. `mygroup@field=value`, allowing you to control grouping (and, in the case of repeatable/multi-value subfields such as Table or Repeater fields, to force multiple `subfield=value` conditions to match against the *same* row/item — documented as `'match-same-1' => '@'` in `Selectors::getReservedChars()`):

```php
$pages->find("mytable@col1=foo, mytable@col2=bar"); // col1 and col2 conditions apply to the same table row
```

## 5. Special / reserved selector properties (from `PageFinder.php`)

These field names are intercepted by `PageFinder::initSelectors()` / `initStatus()` / `getQuery()` rather than treated as ordinary page fields:

| Property | Meaning |
|---|---|
| `sort` | Sort field, see §7. |
| `limit` | Max number of results. Supports `limit=20,10` shorthand meaning start=20, limit=10 (array value form). |
| `start` | Offset to start results from. |
| `include` | One of `hidden`, `unpublished`, `trash`, `all` — relaxes which page statuses may be included (maps to `findHidden`/`findUnpublished`/`findTrash`/`findAll` options). Only `=` operator allowed. |
| `check_access` (alias `checkAccess`) | `check_access=0` disables user-permission (view access) filtering for the query. |
| `status` | Page status bitmask; accepts numeric bit values or name labels (`unpublished`, `hidden`, `trash`, `on`, `locked`, `draft`, `reserved`, etc. — see `PageProperties::$statuses`). Internally rewritten to a `SelectorBitwiseAnd` for `=`/`!=`. |
| `template` (alias implicit `templates_id`) | Template name or ID (or `|`-separated / array of several). |
| `parent`, `parent_id` | Parent page — accepts an ID or a path (e.g. `/products/`), or multiple via OR. |
| `children`, `child` | Match by child page(s); accepts IDs or paths, resolved to IDs. |
| `id` | Page ID(s); supports OR-lists of IDs. |
| `has_parent` (alias `hasParent`) | True if the page has the given page anywhere among its ancestors (not just direct parent). Supports `=` and `!=`. Cannot be OR'd with other fields (`singlesFields`). |
| `num_children` (aliases `numChildren`, `children.count`) | Count of direct children; supports `=`, `<`, `>`, `<=`, `>=`, `!=`. Cannot be OR'd with other fields. |
| `path`, `url` | Match the page's URL path (requires the core `PagePaths` module for anything beyond a plain `=` match or for OR-values). |
| `getTotal` (alias `get_total`) | Whether/how to compute the total match count: boolean-ish value, or `calc`/`count` to pick the counting method. Removed from the selector before querying (an internal option, not a WHERE clause) unless a real field named `getTotal` exists. |
| `_custom` | When `allowCustom` option is enabled, lets a value be treated as a nested raw selector string. |

Source: `PageFinder::initSelectors()`, `initStatus()`, `isModifierField()`, and `getQuery()`'s dispatch block (`if($field1 === 'sort') ... else if 'has_parent' ... else if 'num_children' ...`), all in `wire/core/PageFinder.php`.

`has_parent`, `hasParent`, `num_children`, `numChildren`, `children.count`, `limit`, and `start` are listed in `PageFinder::$singlesFields` — using any of them in an OR'd field list (e.g. `has_parent|title=...`) throws a syntax error.

```php
$pages->find("template=product, status=published, limit=10, start=20, sort=-price");
$pages->find("has_parent=1234");
$pages->find("num_children>0");
$pages->find("include=hidden, title=Draft Page");
$pages->find("check_access=0, template=admin");
```

## 6. Page Reference / relationship selectors — dot notation

**Direct subfield match** (`field.subfield=value`) queries a value on the field's own row/table, e.g. a Page Reference field's referenced page ID, or a named column of a multi-column fieldtype:

```php
$pages->find("category.id=1234");
```

**Subfield dot-notation onto a referenced page's own fields** goes further — ProcessWire resolves `category.title=Foo` by running the query through the referenced field's `Fieldtype::getMatchQuery()` (for `FieldtypePage`, this joins/subqueries the `pages` table), so you can query by any field on the referenced page:

```php
$pages->find("category.title=Foo");         // pages whose 'category' page-ref field points to a page titled "Foo"
$pages->find("category.parent=1234");        // referenced page's parent
```

**Multi-level ("a.b.c") dot chains** are automatically rewritten into an embedded sub-selector. From `PageFinder::preProcessSelector()`: a selector field like `category.parent.title` is split into `category` + the remainder `parent.title`, and converted to the bracket sub-selector form below (`category=[parent.title=...]`) — i.e. multi-dot chains cannot be combined with OR'd (`|`) fields in the same statement.

**Explicit sub-selector — `field=[selector string]`:** wrap a full nested selector in square brackets to match Page Reference (or similar) fields against pages found by that sub-selector, which PageFinder runs as a separate `findIDs()` query and substitutes the resulting IDs:

```php
$pages->find("category=[title=Foo, status<".Page::statusUnpublished."]");
$pages->find("parent=[template=section, name=blog]");
```

Source: `Selectors::getReservedChars()` documents `[` as both `sub-selector-open` (`foo=[bar>0, baz%=text]`) and `api-var-open`; `PageFinder::preProcessSubSelector()` executes the embedded `Selectors` via a fresh `PageFinder`, converting matched pages to an ID list assigned back onto the outer selector's value, and even auto-adds `template=`/`parent_id=` constraints to the sub-query when it can infer them from the Page Reference field's own configured template/parent restrictions (for speed).

**Reverse relationships.** There is no separate "reverse" operator — you query a Page Reference field on the *other* page's fields directly. For example, if pages of template `article` have a Page Reference field `category` pointing at `category` pages, to find all `category` pages that are referenced by at least one published article you would run the equivalent of a sub-selector from the category side, e.g. (on a `category` page's own found-by query) — ProcessWire does not special-case this beyond the same dot-notation/sub-selector mechanism, since Page Reference fields are typically queried from the referencing side. (This is inferred from the fact that no distinct "reverse" selector syntax or operator appears anywhere in `PageFinder.php`; only `field.subfield` / `field=[...]` sub-selector mechanisms were found.)

## 7. Sorting

```php
$pages->find("template=article, sort=title");     // ascending by title
$pages->find("template=article, sort=-title");     // descending (leading "-")
$pages->find("template=article, sort=modified-");   // descending (trailing "-" also works)
$pages->find("template=article, sort=parent.title"); // sort by a field on the parent page
$pages->find("template=article, sort=random");       // random order (MySQL RAND())
$pages->find("template=article, sort=category.title"); // sort by referenced page's field
$pages->find("sort=sort");                           // sort by manual drag-sort order
```

**Multiple sort fields**, in priority order — repeat the `sort=` selector or pipe-separate values (values are reversed internally so pipe order matches intended priority):

```php
$pages->find("sort=featured, sort=-date"); // primary sort featured, secondary sort -date
$pages->find("sort=featured|-date");        // equivalent via OR-list on one 'sort' statement
```

Source: `PageFinder::getQuerySortSelector()` — reads leading or trailing `-` to set `$descending`; dot-notation resolves `parent.<field>`, `template`, `<pagefield>.<subfield>`, `num_children`/`children.count`, and `random`; falls back to a custom field's own `getMatchQuerySort()`. Whether a `sort=` value with `=` operator queues into `$sortSelectors` (collected and applied via `getQuerySortSelector()`) is decided in `PageFinder::getQuery()`; non-`=` operators on `sort`/`page.sort` (`<`,`>`, etc.) instead filter by the manual sort-order integer column.

## 8. Pagination — `limit`, `start`, and autopagination

```php
$pages->find("template=article, limit=10");
$pages->find("template=article, limit=10, start=20");
$pages->find("template=article, limit=20,10"); // shorthand: start=20, limit=10 (comma inside the limit value)
```

**Autopagination.** If a `limit=` is present but no explicit `start=` was given, `PageFinder::getQueryStartLimit()` automatically computes `start` from `$input->pageNum` (ProcessWire's detected page-number segment, e.g. from a URL like `/results/page3/`):

```php
$pageNum = $input->pageNum - 1; // zero-based
$start = $pageNum * $limit;
```

This is how `$pages->find()` combined with ProcessWire's automatic page-numbering (and typically rendered via the `MarkupPagerNav` module) paginates without the developer manually computing an offset — simply supplying `limit=` is enough for page 1, 2, 3, ... URLs to each return the correct slice. Supplying an explicit `start=` selector overrides this auto-calculation.

`findOne`-style calls (e.g. `$pages->get()`, or the `getTotal`/single-result APIs) force `start=0` and (usually) `limit=1` regardless of what was passed, per `PageFinder::initSelectors()`.

`getTotal` (an internal option, or the `getTotal=`/`get_total=` selector field) controls whether the overall match count (ignoring `limit`) is computed and exposed via `PageFinder::getTotal()` / `$pageArray->getTotal()`; it defaults to disabled when finding a single page and enabled whenever a `limit`/`start` is present.

## 9. Known gotchas / quirks

- **Quote values with spaces, commas, or literal pipes.** Unquoted values are terminated by the first unescaped `,` or `|`. Use `"double"` or `'single'` quotes (`title="Hello, World"`), or escape with a backslash (`\,`, `\|`) inside an unquoted value. Source: `Selectors::extractValue()`.
- **`[...]`, `{...}`, `(...)` are also valid quoting characters**, but `[` and `(` carry special meaning (sub-selector / API-var, and OR-group, respectively) — don't use them as plain literal-value quotes if your value could be mistaken for a nested selector or group. `{...}` appears to be a generic alternate quote with no additional parsing semantics found elsewhere in the core.
- **Operators are not allowed unescaped inside unquoted values** — a raw `=`, `>`, `*=`, etc. inside a value will usually be misparsed as a new operator/selector boundary; quote the value if it must contain operator-like characters.
- **`$sanitizer->selectorValue($value)`** (`wire/core/Sanitizer.php`) should be used to escape any dynamic/user-supplied string being interpolated into a selector string, to prevent selector injection (a user supplying something like `, template=admin` to break out of an intended field). It strips/escapes characters meaningful to the selector parser (`" \\ \` | = * % ~ ^ $ # < > [ ] { }` etc.) and adds quotes when needed. A separate `$sanitizer->selectorValueAdvanced($value)` exists specifically for values destined for the `#=` (advanced text search) operator, which permits `+ - * ( ) "` since those are meaningful commands to that operator.
- **`OR`-fields and singles-only fields don't mix.** `has_parent`, `hasParent`, `num_children`, `numChildren`, `children.count`, `limit`, and `start` cannot appear in a pipe-separated OR field list (`PageFinder::$singlesFields`) — doing so throws a syntax error (in debug mode, or on any site installed after Feb 4, 2019 per `PageFinder::arrangeFields()`).
- **`status` accepts both numbers and name labels** (`status=unpublished`, `status=hidden|trash`, etc.) — see `PageProperties::$statuses` for the full label set (`on`, `reserved`, `locked`, `systemID`, `system`, `unique`, `draft`, `flagged`/`incomplete`/`versions`, `internal`, `temp`, `hidden`, `unpublished`, `trash`, `deleted`, `systemOverride`, `corrupted`).
- **Case sensitivity of matching itself is a database/collation concern**, not a selector-syntax concern — most core `Selector::match()` implementations for in-memory matching (used against `WireArray`/`PageArray` objects rather than the database) use case-insensitive functions like `stripos()`/`strcasecmp()` (see `SelectorEqual`, `SelectorContains`, `SelectorStarts`, `SelectorEnds` in `wire/core/Selector.php`); database-level matching depends on the MySQL column collation.
- **API variable interpolation** — `[varname]` or `[varname.property]` inside a value (only when quoted with `[ ]`) is resolved against a small allow-list of API variables (`session`, `page`, `user`) via `Selectors::parseValue()`, or against custom values registered with `Selectors::setCustomVariableValue()` / the hookable `getCustomVariableValue()` method (which also natively supports `[timestamp]`, `[date]`, `[datetime]`, `[year]`):

  ```php
  $pages->find("created_users_id=[user.id]");   // pages created by the current user
  $pages->find("modified>=[date]");
  ```

- **Selector-array form** is also supported everywhere a selector string is: `$pages->find([ 'template' => 'article', 'status' => 1, 'limit' => 10 ])`, including verbose per-item arrays with `field`/`value`/`operator`/`not`/`group`/`find` keys, and numerically-keyed sub-arrays for OR-groups. Source: `Selectors::setSelectorArray()` / `makeSelectorArrayItem()` in `wire/core/Selectors.php`. This document focuses on the string syntax; the array form follows the same underlying grammar.
