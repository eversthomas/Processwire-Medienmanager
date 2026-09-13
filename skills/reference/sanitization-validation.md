# Sanitization & Validation

This reference documents ProcessWire's input sanitization system: the `$sanitizer` API variable
(`wire/core/Sanitizer.php`), how `Fieldtype::sanitizeValue()` fits into the page-save pipeline
(`wire/core/Fieldtype.php`, `wire/core/PageValues.php`), how `$input` relates to sanitization
(`wire/core/WireInput.php`, `wire/core/WireInputData.php`), and where "required field" validation
actually happens (`wire/core/InputfieldWrapper.php`, `wire/core/Inputfield.php`). All claims below
were verified by reading the corresponding core source files directly.

## 1. The `$sanitizer` API variable

`Sanitizer` (`wire/core/Sanitizer.php`) is a core class registered as the `sanitizer` API variable
during boot. In `wire/core/ProcessWire.php` it is set up with:

```php
$this->wire('sanitizer', new Sanitizer());
```

Because `Sanitizer extends Wire`, any of the following work identically anywhere the ProcessWire
API is available:

```php
$clean = $sanitizer->text($dirty);          // via the $sanitizer API variable
$clean = $this->wire('sanitizer')->text($dirty);  // explicit wire() call
$clean = $this->sanitizer->text($dirty);     // property access inside a Wire-derived class
$clean = wire('sanitizer')->text($dirty);    // procedural API (if enabled)
```

Sanitizer methods are also callable directly from `$input->get`, `$input->post`, `$input->cookie`
(and `$input->whitelist`) — see section 5.

### Adding custom sanitizers

Per the class doc comment, you can hook in your own sanitizer method (e.g. in `/site/ready.php`):

```php
$sanitizer->addHook('zip', function(HookEvent $event) {
    $sanitizer = $event->object;
    $value = $event->arguments(0);
    $value = $sanitizer->digits($value, 5);
    if(strlen($value) < 5) $value = '';
    $event->return = $value;
});
$cleanValue = $sanitizer->zip($dirtyValue);
```

### Combined/"combo" sanitizer calls (3.0.125+)

Sanitizer method names can be combined with underscores, and a trailing number on a
string-returning method is treated as a max-length:

```php
$cleanValue = $sanitizer->text_entities($dirtyValue);  // text() then entities()
$cleanValue = $sanitizer->text20($dirtyValue);          // text() with maxLength=20
```

This is implemented via `Sanitizer::sanitize()`, `Sanitizer::validate()`, `Sanitizer::valid()`, and
the magic `___callUnknown()` method at the bottom of `Sanitizer.php`.

## 2. Sanitizer methods (verified from `Sanitizer.php`)

All signatures and behaviors below were read directly from the method bodies/doc comments in
`wire/core/Sanitizer.php`. Line numbers refer to that file as of core 3.0.257.

### 2.1 Text / string sanitizers

| Method | Signature | Behavior |
|---|---|---|
| `text()` | `text($value, $options = array())` | Sanitizes to a single line of plain text, no HTML. Strips tags (`stripTags`, default true), collapses to a single line unless `multiLine` option is true (replaces newlines with `newlineReplacement`, default `' '`), truncates to `maxLength` (default **255** chars) and `maxBytes` (default `maxLength*4`), trims by default. Many other options: `stripMB4`, `stripQuotes`, `stripSpace`/`reduceSpace`, `allowableTags`, `convertEntities`, `truncateTail` (truncate from head vs tail), `inCharset`/`outCharset`. |
| `textarea()` | `textarea($value, $options = array())` | Like `text()` but multi-line (`multiLine` defaults true), default `maxLength` **16384** chars (`maxBytes` = `maxLength*4`). Normalizes `\r\n` to `\n` unless `allowCRLF`. Has a `stripIndents` option. Still strips HTML tags — **not** for rich text; use `purify()` for that. |
| `line()` | `line($value, $maxLength = 0, array $options = array())` | Same as `text()` but with **no default max length** (0 = unlimited) unless you pass one. Since 3.0.157. |
| `lines()` | `lines($value, $maxLength = 0, $options = array())` | Multi-line equivalent of `line()` — no default max length unless given. |
| `string()` | `string($value, $sanitizer = null)` | Converts *any* value (object w/ `__toString()`, null, bool, array, scalar) to a string. Does **not** sanitize for safety on its own — arrays become `"array-{count}"`, objects without `__toString()` become the class name. Optional 2nd arg applies another named sanitizer afterward. |
| `word()` | `word($value, array $options = array())` | Returns the first "word" found in a string, with many options (`keepNumbers`, `keepHyphen`, `keepUnderscore`, `separator`, `maxWords`, `minWordLength`, `maxWordLength`, `ascii`, `beautify`, etc). Since 3.0.162. |
| `words()` | `words($value, array $options = array())` | Returns a string containing only "words" (defaults: keeps hyphens/underscores, space-separated, `maxLength` 1024). Built on `word()`. Since 3.0.195. |
| `wordsArray()` | `wordsArray($value, array $options = array())` | Underlying array-returning version used by `word()`/`words()`. |
| `chars()` | `chars($value, $allow = '', $replacement = '', $collapse = true, $mb = null)` | Low-level character allow-list filter; `$allow` supports `[alpha]`/`[digit]` placeholders. |
| `trim()` | `trim($str, $chars = '', $method = 'trim')` | UTF-8 aware trim (also supports `ltrim`/`rtrim` via `$method`). |
| `truncate()` / `trunc()` | `truncate($str, $maxLength = 300, $options = array())` | Truncates a string to a max length, word/sentence aware options. `trunc()` is a shorter-named variant. |
| `removeNewlines()` | `removeNewlines($str, $replacement = ' ')` | Replaces `\r\n`, `\r`, `\n` with `$replacement`. |
| `removeWhitespace()` | `removeWhitespace($str, $options = array())` | Removes/replaces whitespace, including many Unicode whitespace code points and HTML whitespace entities (`allow`, `replace`, `collapse`, `trim`, `html` options). |
| `reduceWhitespace()` | `reduceWhitespace($str, $options = array())` | Collapses consecutive whitespace to a single instance. |
| `normalizeWhitespace()` | `normalizeWhitespace($str, $options = array())` | Normalizes whitespace variants to standard space/newline. |
| `removeMB4()` | `removeMB4($value, array $options = array())` | Strips 4-byte UTF-8 characters (emoji, some CJK extension characters) which older MySQL `utf8` (non-`utf8mb4`) columns cannot store; replaces with U+FFFD by default. Works recursively on arrays. |
| `hyphenCase()` / `kebabCase()` | `hyphenCase($value, array $options = array())` | Converts to `hello-world` style (kebab-case is an alias name). |
| `snakeCase()` | `snakeCase($value, array $options = array())` | Converts to `hello_world` style. |
| `camelCase()` | `camelCase($value, array $options = array())` | Converts to `helloWorld` style. |
| `pascalCase()` | `pascalCase($value, array $options = array())` | Converts to `HelloWorld` style. |
| `entities()` | `entities($str, $flags = ENT_QUOTES, $encoding = 'UTF-8', $doubleEncode = true)` | Thin wrapper around PHP `htmlentities()`. Use for **output**, not input sanitization. |
| `entities1()` | `entities1($str, $flags = ENT_QUOTES, $encoding = 'UTF-8')` | Same as `entities()` but `$doubleEncode = false` (won't double-encode already-encoded entities). |
| `entitiesA()` / `entitiesA1()` | `entitiesA($value, $flags = ENT_QUOTES, $encoding = 'UTF-8', $doubleEncode = true)` | Recursively entity-encodes arrays (string values/keys), objects (via `__toString()` or class name), leaves int/float/bool untouched. `entitiesA1()` is the non-double-encoding variant. Since 3.0.194. |
| `entitiesMarkdown()` | `entitiesMarkdown($str, $options = array())` | Entity-encodes text while converting a safe subset of Markdown (`**bold**`, `*em*`, links, `~~strike~~`, code) to HTML, or full Markdown if `$options === true`. |
| `unentities()` | `unentities($str, $flags = ENT_QUOTES, $encoding = 'UTF-8')` | Decodes HTML entities back to characters. |
| `purify()` | `purify($str, array $options = array())` | Runs the string through **HTML Purifier** (via the `MarkupHTMLPurifier` module) to produce safe, well-formed HTML. This is the correct tool for rich-text/HTML field values, unlike `text()`/`textarea()` which strip all tags. |
| `purifier()` | `purifier(array $options = array())` | Returns a configured `MarkupHTMLPurifier` module instance for direct reuse. |
| `markupToText()` | `markupToText($value, array $options = array())` | Converts HTML markup to plain text (not just tag-stripping — more like a text extraction). |
| `markupToLine()` | `markupToLine($value, array $options = array())` | Same, collapsed to a single line. |
| `json()` | `json($value, array $options = [])` | Sanitizes/validates a JSON string. |
| `maxLength()` | `maxLength($value, $maxLength = 128, $maxBytes = null)` | Generic length limiter that works on strings, arrays (max item count), ints and floats (max digit count) — returns the same type given. Since 3.0.125. |
| `minLength()` | `minLength($value, $minLength = 1, $padChar = '', $padLeft = false)` | **Validates by default**: returns the string unchanged if it meets the minimum length, or blank string if it doesn't — unless `$padChar` is given, in which case it pads to meet the minimum. This is one of the few sanitizer methods that behaves like a validator by default. |
| `maxBytes()` | `maxBytes($value, $maxBytes = 128)` | Byte-length limiter (multibyte-safe truncation), built on `maxLength()`. |

### 2.2 Name / slug sanitizers (page names, field names, template names, filenames)

All of these are built on the internal `nameFilter()` method, which allows ASCII
alphanumerics plus whatever "extra" characters are passed in, replaces disallowed characters with
a replacement character, and can optionally "beautify" (trim/collapse punctuation) and/or
transliterate UTF-8 to ASCII (`Sanitizer::translate` constant, `2`) using the replacement table
from the `InputfieldPageName` module config.

| Method | Signature | Behavior |
|---|---|---|
| `name()` | `name($value, $beautify = false, $maxLength = 128, $replacement = '_', $options = array())` | General "name" format: ASCII letters/digits, hyphen, underscore, period. Non-name characters replaced with `$replacement` (default `_`). `$beautify = true` cleans up doubled/leading/trailing punctuation; `Sanitizer::translate` also transliterates. `$options` supports `allowedExtras`, `allowAdjacentExtras`, `allowDoubledReplacement`. |
| `names()` | `names($value, $delimeter = ' ', $allowedExtras = ['-','_','.'], $replacementChar = '_', $beautify = false)` | Sanitizes a string or array of *multiple* names (space/comma/pipe delimited by default), returning the same type given. |
| `pageName()` | `pageName($value, $beautify = false, $maxLength = 128, array $options = array())` | Sanitizes a value for use as a ProcessWire **page name**. Lowercase-oriented ASCII name format by default (letters/digits/`-`/`_`/`.`). `$beautify` accepts `true`, `Sanitizer::translate`, `Sanitizer::toAscii` (UTF-8 → punycode `xn-`), `Sanitizer::toUTF8` (punycode → UTF-8), or `Sanitizer::okUTF8` (allow UTF-8 chars, implied when `$config->pageNameCharset === 'UTF8'`). `$maxLength` default 128; `$beautify`/`$maxLength` can each be replaced with an `$options` array. |
| `pageNameTranslate()` | `pageNameTranslate($value, $maxLength = 128)` | Page name sanitize with transliteration applied. |
| `pageNameUTF8()` | `pageNameUTF8($value, $maxLength = 128)` | Page name sanitize allowing UTF-8 page names directly (no ASCII conversion), applicable when `$config->pageNameCharset` is `'UTF8'`. |
| `pagePathName()` | `pagePathName($value, $beautify = false, $maxLength = 2048)` | Sanitizes a full page **path** (multiple `/`-delimited page names) — sanitizes each segment via `pageName()`. Not guaranteed to match an actual page, just sanitized. |
| `pagePathNameUTF8()` | `pagePathNameUTF8($value)` | UTF-8-preserving variant of `pagePathName()`, only differs when `$config->pageNameCharset === 'UTF8'`. |
| `fieldName()` | `fieldName($value, $beautify = false, $maxLength = 128)` | Sanitizes to ProcessWire **field name** / PHP-variable-name format: ASCII letters/digits/underscore only (no hyphen or period, since those aren't valid in PHP variable names). |
| `fieldSubfield()` | `fieldSubfield($value, $limit = 1)` | Sanitizes `field.subfield` style strings (used in selectors/API), e.g. `a.b.c` → `a.b` by default; `$limit = -1` allows unlimited subfields, `$limit = 0` reduces to just the base field name. Since 3.0.126. |
| `templateName()` | `templateName($value, $beautify = false, $maxLength = 128)` | Sanitizes a ProcessWire **template name** — allows underscore and hyphen as extras (marked `#pw-internal`). |
| `varName()` | `varName($value)` | Sanitizes to a PHP-variable-name-safe string (letters/digits/underscore, cannot start with a digit). Marked `#pw-internal`. |
| `attrName()` | `attrName($value, $maxLength = 255)` | Sanitizes to an ASCII-only HTML attribute name. Since 3.0.133. |
| `htmlClass()` | `htmlClass($value)` | Sanitizes a single ASCII-only HTML `class` attribute token (allows `-_:@a-zA-Z0-9`, cannot start with a digit, cannot be extras-only). Since 3.0.212. |
| `htmlClasses()` | `htmlClasses($value, $getArray = false)` | Sanitizes a space-separated (or array) list of HTML classes, de-duplicated, via `htmlClass()`. Since 3.0.212. |
| `filename()` | `filename($value, $beautify = false, $maxLength = 128)` | Sanitizes a **file basename** (not a path — runs `basename()` first) to ProcessWire name format; preserves mixed case and file extension when truncating for length. |
| `path()` | `path($value, $options = array())` | **Validates** (returns `false` if invalid, otherwise the path) that a string matches ProcessWire's path convention `[-_./a-z0-9]` — rejects `//`, `/./`, and (by default) `..`. Options: `allowDotDot` (default false), `maxLength` (default 1024). Primarily useful for internal PW paths, not arbitrary filesystem paths. |
| `username()` | `username($value)` | Deprecated alias of `pageName()`. |

### 2.3 Numeric sanitizers

| Method | Signature | Behavior |
|---|---|---|
| `int()` | `int($value, array $options = array())` | Casts to `(int)`, then clamps to `min`/`max` (defaults: `min=0`, `max=PHP_INT_MAX` — i.e. **unsigned by default**). `blankValue` (default `0`) is returned for `null`/`''`. Objects become `1`. |
| `intUnsigned()` | `intUnsigned($value, array $options = array())` | Alias for `int()` with the same unsigned defaults. |
| `intSigned()` | `intSigned($value, array $options = array())` | Like `int()` but `min` defaults to `-PHP_INT_MAX` (allows negatives). |
| `digits()` | `digits($value, $maxLength = 1024)` | Strips everything except ASCII digits `0-9` (keeps as a **string**, e.g. useful for zip codes / phone numbers where leading zeros matter). |
| `float()` | `float($value, array $options = array())` | Sanitizes to a float. Options: `precision`/`mode` (rounding), `blankValue` (default `0.0`), `min`/`max`, `getString` (return a formatted string instead of a PHP float — supports locale-aware `'f'`, non-locale `'F'`, or scientific notation `'e'`/`'E'`). |
| `range()` | `range($value, $min = null, $max = null)` | Clamps a value between `$min` and `$max`; returns `float` if either bound is a float, else `int`. Since 3.0.125. |
| `min()` | `min($value, $min = PHP_INT_MIN)` | Shorthand for `range($value, $min, null)`. |
| `max()` | `max($value, $max = PHP_INT_MAX)` | Shorthand for `range($value, null, $max)`. |
| `date()` | `date($value, $format = null, array $options = array())` | Validates/normalizes a date string or unix timestamp. Returns unix timestamp if no `$format` given, `null` if invalid/unparseable (with `default` option override), supports `min`/`max` bounds and a `strict` mode. |
| `bool()` | `bool($value)` | Converts to boolean, recognizing the strings `"0"`/`"false"` as false and `"1"`/`"true"` as true (any other non-empty string is treated as true — note this is **not** the same as PHP's native truthiness for strings like `"0.0"`). |
| `bit()` | `bit($value)` | Same as `bool()` but returns `int` `0`/`1`. Since 3.0.125. |
| `checkbox()` | `checkbox($value, $yes = true, $no = false)` | Returns `$yes`/`$no` (customizable) based on whether `$value` represents a checked checkbox (`''`, `'0'`, `null`, `false`, or empty array/other empty value all count as "unchecked"). Since 3.0.128. |

### 2.4 Array sanitizers

| Method | Signature | Behavior |
|---|---|---|
| `array()` (hookable `___array()`) | `array($value, $sanitizer = null, array $options = array())` | General-purpose "make this an array" sanitizer. Converts CSV/delimited strings to arrays (`csv` option, delimiters `|`/`,` by default) unless disabled, trims string items, supports `maxItems`, `maxDepth` (nested arrays; default 0 = flatten/drop nested arrays), and an optional `sanitizer`/`keySanitizer` method name applied to every item/key. Throws `WireException` if an unknown sanitizer name is given. |
| `arrayVal()` | `arrayVal($value, $options = array())` | Same as `array()` but **does not** attempt CSV/delimiter string-splitting (a delimited string becomes a single-item array). Since 3.0.165. |
| `textArray()` | `textArray($value, array $options = [])` | Converts a value to an array of sanitized text strings (default per-item sanitizer is `text`), with `maxItems`, `maxDepth` (default 5), `maxItemLength`, `assoc`, `keySanitizer` (default `'auto'`), `verbose` (expand objects), `types` (preserve int/float/bool). Since 3.0.256. |
| `intArray()` | `intArray($value, $options = array())` | Converts to an array of integers (each run through `int()`); `strict` option (bool) removes rather than coerces invalid items. |
| `intArrayVal()` | `intArrayVal($value, $options = array())` | Like `intArray()` but CSV splitting disabled by default and `strict` defaults to `true` (removes non-integer values instead of converting them). Since 3.0.165. |
| `minArray()` | `minArray($data, $allowEmpty = false, $convert = false)` | Removes empty values from an array (recursively). `$allowEmpty` can be `true`/`false`, an array of keys to keep, or an array of empty-value "types" to retain (e.g. keep `0` but not `''`). |
| `flatArray()` | `flatArray($value, array $options = array())` | Flattens a multi-dimensional array to one dimension; `preserveKeys`, `maxDepth` options. Since 3.0.160. |
| `wordsArray()` | `wordsArray($value, array $options = array())` | Returns an array of individual "words" extracted from a string (backing implementation for `word()`/`words()`). |
| `option()` | `option($value, array $allowedValues = array())` | Whitelist check: returns `$value` only if present in `$allowedValues`, else `null`. |
| `options()` | `options(array $values, array $allowedValues = array())` | Array version of `option()` — returns the subset of `$values` present in `$allowedValues`. |

### 2.5 Special-purpose sanitizers (selectors, email, URL)

| Method | Signature | Behavior |
|---|---|---|
| `selectorField()` | `selectorField($value)` | Sanitizes a **field name** for safe use in a ProcessWire selector string (thin wrapper: `nameFilter($value, ['_'], '_')`). |
| `selectorValue()` | `selectorValue($value, $options = array())` | **The** method to use for sanitizing any value being interpolated into a PW selector string, so it can't break out of / hijack the selector syntax. May remove characters, escape characters, or wrap the value in quotes as needed. Accepts a string or (3.0.127+) an array, which becomes an OR-value string like `foo|bar|baz`. Options include `maxLength` (default 100), `useQuotes`, `allowArray`, `allowSpace`, `operator`, `emptyValue`, `blacklist`, `whitelist`, `quotelist`, and a `version` switch (1 or 2, default 2 since 3.0.156). |
| `selectorValueAdvanced()` | `selectorValueAdvanced($value, array $options = array())` | Variant of `selectorValue()` specifically for the `#=` "advanced text search" operator, which allows `+ - * ( ) "` as meaningful search-syntax characters rather than stripping them. Since 3.0.182. |
| `email()` | `email($value, array $options = array())` | Sanitizes **and validates** an email address — returns a valid address or a blank string if invalid (this is a validate-and-sanitize hybrid, not simple stripping). Options: `allowIDN` (bool or `2` to also allow UTF-8 local-part/SMTPUTF8), `getASCII`/`getUTF8` (IDN conversion of the returned value), `checkDNS` (slow — verifies host has a DNS record), `throw` (throw `WireException` with details instead of silently returning blank). |
| `emailHeader()` | `emailHeader($value, $headerName = false)` | Strips newline injection vectors (`\n`, `\r`, and encoded variants) from a string intended for use in an email header, to prevent header injection. |
| `url()` | `url($value, $options = array())` | Sanitizes a URL. Defaults: `allowRelative` true, `allowIDN` false, `allowQuerystring` true, `disallowSchemes` = `['file','javascript']`, `requireScheme` true, `stripTags`/`stripQuotes` true, `maxLength` 4096. Handles `tel:` scheme specially, IDN/percent-encoded domains, and scheme allow/deny lists (`allowSchemes`, `disallowSchemes`) with an optional `throw` option. |
| `httpUrl()` | `httpUrl($value, $options = array())` | `url()` with `requireScheme = true`, `allowRelative = false`, and `allowSchemes` defaulting to `['http','https']`. Since 3.0.129. |

### 2.6 Meta / utility methods

- `sanitize($value, $method = 'text')` — programmatically invoke a sanitizer (or CSV-combo of
  sanitizers) by string name, including support for the `text20`-style trailing-maxLength
  shorthand. Throws `WireException` for an unknown method name.
- `validate($value, $method = 'text', $fallback = null)` — runs `sanitize()` and returns the
  sanitized value **only if it was unchanged** by sanitization (ignoring surrounding whitespace and
  type coercion); otherwise returns `$fallback` (default `null`). This is how ProcessWire turns any
  sanitizer into a strict validator.
- `valid($value, $method = 'text', $strict = false)` — boolean wrapper around `validate()`.
- `methodExists($name, $allowCombos = true)` — checks whether a sanitizer method (including hooked
  and combo names) exists.
- `getAll()`, `getTextTools()`, `getNumberTools()` — introspection/helper accessors (`getTextTools()`
  returns a `WireTextTools` instance used internally for multibyte-safe string ops).
- `validateFile($filename, array $options = array())` — validates a file, e.g. for upload security
  checks (SVG/PHP content sniffing etc. — see source for full option list).

## 3. `Fieldtype::sanitizeValue()`

### 3.1 Declaration and role

In `wire/core/Fieldtype.php`:

```php
abstract public function sanitizeValue(Page $page, Field $field, $value);
```

Key facts verified from the source and its doc comment (`wire/core/Fieldtype.php` ~line 447-462):

- It is **abstract and required** — every Fieldtype module must implement it.
- It is **not hookable** (no `___` prefix, and it does not appear in the `@method` hookable-methods
  list at the top of `Fieldtype.php`).
- Per the doc comment: *"This method should remove anything that's invalid from the given value. If
  it can't be sanitized, it should be made blank."* and *"This method filters every value set to a
  Page instance, so it should do its thing as quickly as possible."*
- It returns the sanitized value (string, int, `WireArray`, or other object depending on Fieldtype).

### 3.2 When it's called

`sanitizeValue()` is invoked from `wire/core/PageValues.php` in two places:

1. **`PageValues::setFieldValue()`** (the method backing `$page->set($field, $value)` /
   `$page->{$field} = $value`) — near the end of the method:

   ```php
   // ensure that the value is in a safe format and set it
   $value = $fieldtype->sanitizeValue($page, $field, $value);
   $page->_parentSet($key, $value);
   ```

   This means **every** assignment to a page field — whether from your own code, from
   `$page->setImportValue()`, or from Inputfield-processed form submissions — is sanitized here
   before it lands on the `Page` object, whether or not you ever call `$pages->save()`.

2. **`PageValues::getFieldValue()`** — when a value is "woken up" from the database (via
   `wakeupValue()`), it is also passed through `sanitizeValue()` before being stored back onto the
   `Page` object, as an extra safety net for values pulled from the DB.

`Pagefile`/`Pagefiles` (`wire/core/Pagefile.php`) also call `$fieldtype->sanitizeValue()` directly in
a few places when manipulating file field values.

`sanitizeValue()` is **not** a save-blocking validation step — it doesn't throw or reject; it
silently coerces or blanks bad data. Nothing in `Pages.php`/`PagesEditor.php` calls `sanitizeValue()`
around `$pages->save()` beyond what `setFieldValue()` already triggers on assignment — `save()`
persists whatever sanitized value is already sitting on the `Page` object.

### 3.3 Real examples from core Fieldtype modules

**`FieldtypeText`** (`wire/modules/Fieldtype/FieldtypeText.module`) — the simplest possible
implementation, a pass-through (actual constraint enforcement for plain text fields happens at the
Inputfield/UI layer, not here):

```php
public function sanitizeValue(Page $page, Field $field, $value) {
    return $value;
}
```

**`FieldtypeEmail`** (`wire/modules/Fieldtype/FieldtypeEmail.module`, ~line 57) — a concrete
field-level validation example: rejects overlong values and delegates to `$sanitizer->email()`,
passing a field-configurable option through:

```php
public function sanitizeValue(Page $page, Field $field, $value) {
    $sanitizer = $this->wire()->sanitizer;
    $max = $this->getMaxEmailLength();
    if(strlen($value) > $max && $sanitizer->getTextTools()->strlen($value) > $max) return '';
    return $sanitizer->email($value, array(
        'allowIDN' => (int) $field->get('allowIDN')
    ));
}
```

**`FieldtypeURL`** (`wire/modules/Fieldtype/FieldtypeURL.module`, ~line 37) — delegates to
`$sanitizer->url()`, passing through per-field configuration (`noRelative`, `allowIDN`,
`allowQuotes`) as sanitizer options:

```php
public function sanitizeValue(Page $page, Field $field, $value) {
    return $this->wire()->sanitizer->url($value, array(
        'allowRelative' => $field->get('noRelative') ? false : true,
        'allowIDN' => $field->get('allowIDN') ? true : false,
        'stripQuotes' => $field->get('allowQuotes') ? false : true
    ));
}
```

**`FieldtypeInteger`** (`wire/modules/Fieldtype/FieldtypeInteger.module`, ~line 95) — a more involved
example that tries to salvage a usable integer out of messy string input (currency symbols,
scientific notation, trailing junk) before falling back to a simple cast:

```php
public function sanitizeValue(Page $page, Field $field, $value) {
    if(is_string($value) && strlen($value) && !ctype_digit(ltrim($value, '-'))) {
        $value = $this->sanitizeValueString($value);
    } else {
        $value = strlen("$value") ? (int) $value : '';
    }
    return $value;
}
```

**`FieldtypePageTitle`** (`wire/modules/Fieldtype/FieldtypePageTitle.module`, ~line 42) — a minimal
override that just trims whitespace:

```php
public function sanitizeValue(Page $page, Field $field, $value) {
    if(is_string($value)) $value = trim($value);
    return $value;
}
```

**Pattern to follow for a custom Fieldtype**: implement `sanitizeValue()` to (a) coerce the incoming
value to the expected PHP type, (b) run it through the appropriate `$sanitizer` method(s), (c)
optionally read field-specific settings via `$field->get('yourSetting')` to parametrize the
sanitizer call, and (d) return a "safe to store" blank/default value rather than throwing when the
input can't be salvaged.

## 4. How "required" field validation actually happens

This was traced precisely through the source rather than assumed:

- `Field` (`wire/core/Field.php`) exposes `required` and `requiredIf` as plain properties
  (`@property int|bool|null $required`, `@property string|null $requiredIf`, documented at the top
  of the class) — but `Field.php` itself contains **no logic that enforces them**.
- `Fieldtype::sanitizeValue()` and the `$pages->save()` / `PagesEditor.php` save pipeline contain
  **no check of the `required` property at all** (confirmed by searching `Pages.php` and
  `PagesEditor.php` for `required` — no relevant matches).
- The actual enforcement lives in the **Inputfield / form-processing layer**:
  - `Inputfield::isEmpty()` (`wire/core/Inputfield.php`, ~line 1598) provides the default emptiness
    check (`strlen((string) $this->attr('value')) === 0`, or `count() === 0` for arrays), which
    individual Inputfield subclasses may override.
  - `InputfieldWrapper::___processInput(WireInputData $input)` (`wire/core/InputfieldWrapper.php`,
    ~line 1323-1355) is what actually enforces it, right after calling `$child->processInput($input)`
    for each child field:

    ```php
    // check if a value is required and field is empty, trigger an error if so
    if($child->attr('name') && $child->getSetting('required') && $child->isEmpty()) {
        $requiredLabel = $child->getSetting('requiredLabel');
        if(empty($requiredLabel)) $requiredLabel = $this->requiredLabel;
        $child->error($requiredLabel);
    }
    ```

**Practical implication**: "required" is a **form/UI-layer concern**, enforced only when a form built
from Inputfields (e.g. the page-edit form in the admin, or a front-end form built with
`InputfieldWrapper`/`InputfieldForm` and its `processInput()`) is processed. It is **not** enforced
if you set field values and call `$page->save()` directly via the API — the API will happily save a
page with a blank "required" field. If you need required-field enforcement in your own API-driven
code, you must check `$field->required` (and evaluate `requiredIf` selectors yourself if used) and/or
throw your own exception before saving.

## 5. `$input` and its relationship to sanitization

Source: `wire/core/WireInput.php` (the `$input` API variable) and `wire/core/WireInputData.php`
(the object returned by `$input->get`, `$input->post`, `$input->cookie`, `$input->whitelist`).

- **Raw, unsanitized access is the default.** Per the doc comment at the top of
  `WireInputData.php`: *"No sanitization or filtering is done, other than disallowing
  multi-dimensional arrays in input."* Plain property/array access — `$input->get->foo`,
  `$input->get['foo']`, or `$input->get('foo')` with no second argument — returns the **raw** value
  (only slashes-stripping and an array-depth limit via `$config->wireInputArrayDepth` are applied).
- **Sanitizer methods are callable directly on `$input->get`/`post`/`cookie`.** This works via
  `WireInputData::___callUnknown()` (~line 514), which looks up the input value by name and passes
  it through the matching `Sanitizer` method:

  ```php
  $id = $input->get->int('id');            // GET var 'id' sanitized via Sanitizer::int()
  $name = $input->post->text('name');       // POST var 'name' sanitized via Sanitizer::text()
  $comments = $input->post->textarea('comments');
  ```

- **`WireInput::get()`/`post()`/`cookie()`** (the callable form, 3.0.125+) accept a sanitizer name
  (or CSV combo, whitelist array, callback, or single allowed int) as the 2nd argument, and an
  optional fallback as the 3rd:

  ```php
  $q = $input->get('q', 'text');                       // sanitized with Sanitizer::text()
  $comments = $input->post('comments', 'textarea,entities');
  $color = $input->get('color', ['red','blue','green'], 'red'); // whitelist + fallback
  $qty = $input->get('qty', 'int', 1);                  // fallback if missing/invalid
  ```

  Per `WireInput::get()`'s doc comment: *"If given no `$valid` argument, returns unsanitized value or
  NULL if not present."* — i.e. `$input->get('q')` alone is still raw/unsanitized.
- **Gotcha**: because raw access is silent and easy (`$input->get->foo`), it's easy to accidentally
  use an unsanitized value. Always call a sanitizer method (either via `$sanitizer->...()` on the
  fetched value, or directly via `$input->get->text('foo')` / `$input->get('foo', 'text')`).

## 6. Practical guidance

**Sanitizing a page name before creating a page:**

```php
$name = $sanitizer->pageName($input->post('title'), Sanitizer::translate);
// Sanitizer::translate transliterates non-ASCII (e.g. "Café" -> "cafe") using
// the InputfieldPageName module's replacement table (see nameFilter() in Sanitizer.php).
$page = new Page();
$page->template = 'basic-page';
$page->parent = $parent;
$page->name = $name;      // Fieldtype::sanitizeValue() also runs on assignment
$page->title = $sanitizer->text($input->post('title'));
$page->save();
```

**Sanitizing a value from `$input` before using it in a selector via `selectorValue()`:**

```php
$q = $input->get->text('q');                    // sanitize the raw query text first
$results = $pages->find("title|body%=" . $sanitizer->selectorValue($q));
```

Always run any variable value through `selectorValue()` (not just `text()`) before concatenating it
into a selector string — `selectorValue()` is what prevents the value from breaking out of, or
altering the meaning of, the selector syntax itself.

**Sanitizing text before saving to a field:**

```php
$page->of(false);
$page->summary = $sanitizer->text($input->post('summary'));         // plain text field
$page->body = $sanitizer->purify($input->post('body'));             // rich text / HTML field
$page->save();
```

Use `text()`/`textarea()`/`line()`/`lines()` for plain-text fields (they strip all HTML), and
`purify()` for any field where HTML markup is expected to be retained (e.g. a CKEditor/TinyMCE rich
text field) — `purify()` runs the value through HTML Purifier rather than stripping tags outright.

**Sanitizing numeric IDs/integers from GET/POST:**

```php
$pageId = (int) $input->get->int('id');   // Sanitizer::int() defaults to unsigned, min=0
```

## 7. Gotchas

- **Sanitizers coerce, they don't reject (with a few exceptions).** Per the class doc comment, "Most
  methods in the Sanitizer class focus on sanitization rather than validation" — they always return
  a usable value of the expected type rather than throwing. Exceptions that behave more like
  validators: `email()` (returns blank string if invalid rather than a mangled email), `minLength()`
  (returns blank rather than a too-short string, unless `$padChar` given), `path()` (returns `false`
  on invalid input rather than a sanitized path). If you need strict "unchanged or reject" semantics
  for any sanitizer, use `Sanitizer::validate()` / `Sanitizer::valid()` rather than the raw sanitizer
  call.
- **Truncation is silent.** `text()` defaults to `maxLength = 255` / `maxBytes = 1020`; `textarea()`
  defaults to `maxLength = 16384`. Values longer than that are silently truncated (from the tail by
  default; `truncateTail = false` truncates from the head instead) — there is no error or exception,
  just a shorter string. Same applies to `pagePathName()` (`maxLength = 2048`), `name()`/`pageName()`
  (`maxLength = 128` by default), etc. Always check documented defaults if length matters.
- **`string()` is not a safety sanitizer.** `Sanitizer::string()` only guarantees you get a PHP
  `string` type back (handling objects/arrays/bools/null) — it explicitly does not make any
  assumption about what is a "safe" string. Always chain another sanitizer after it, or call it via
  its optional 2nd `$sanitizer` argument.
- **Raw `$input` access bypasses sanitization entirely.** `$input->get->foo` / `$input->get['foo']`
  return the raw value (see section 5); only calling a named sanitizer method (`$input->get->text('foo')`
  or `$sanitizer->text($input->get->foo)`) sanitizes it.
- **4-byte UTF-8 (emoji, some CJK) can break older MySQL columns.** `removeMB4()` exists specifically
  to strip 4-byte UTF-8 sequences for database columns using the older `utf8` charset (as opposed to
  `utf8mb4`); `text()`/`textarea()` also expose a `stripMB4` option for this reason.
  Multibyte-awareness in general: the `Sanitizer` constructor
  (`wire/core/Sanitizer.php` `__construct()`) detects `mb_*` function availability
  (`$this->multibyteSupport`) and sets `mb_internal_encoding("UTF-8")`; many methods (`text()`,
  `maxLength()`, `filename()`, etc.) branch on this flag to use `mb_strlen()`/`mb_substr()` instead of
  byte-based `strlen()`/`substr()` when available, to avoid corrupting multibyte characters when
  truncating.
- **`bool()` treats any non-empty, non-`"0"`/`"false"` string as `true`.** E.g.
  `$sanitizer->bool("no")` returns `true` — it only special-cases the literal strings `"0"` and
  `"false"` (case-insensitively, after trimming) as false. Don't assume natural-language negatives are
  handled.
- **`Fieldtype::sanitizeValue()` runs on every assignment, not just on save.** Because it fires from
  `PageValues::setFieldValue()`, simply doing `$page->fieldName = $value;` sanitizes immediately —
  useful to know if you're debugging why a value "changed" right after you set it (e.g. an email
  address got lowercased/rejected, a URL got its scheme normalized, etc.) even before calling
  `$pages->save()`.
- **`required` is not a database/API-level constraint.** As detailed in section 4, it's enforced only
  by `InputfieldWrapper::processInput()` when a form is processed — direct API saves are not blocked
  by a blank required field.
- **`selectorValue()` may return non-string values.** If you set the `emptyValue` option, the return
  type can be whatever you specify (e.g. `false`) instead of a string, for you to detect and branch
  on.
