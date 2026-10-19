# LSR Text Catalog

`lsr/text-catalog` provides standalone NEON source-copy loading, source lookup, gettext compilation, and optional Nette/Symfony Console integration. Namespace: `Lsr\TextCatalog\`.

The independently versioned [JavaScript package](https://github.com/Heroyt/lsr-text-catalog-js) provides optional Vue/gettext helpers and Vite integration.

## Installation

Install with Composer from [Packagist](https://packagist.org/packages/lsr/text-catalog):

```sh
composer require lsr/text-catalog
```

The standard Packagist installation needs no custom Composer repository. To use the optional Vue/Vite integration, install the independently versioned [`lsr-text-catalog`](https://www.npmjs.com/package/lsr-text-catalog) npm package as well.

**Upgrading to 0.3:** frontend artifacts move to format 2 (per-group/per-locale lazy modules), readable only by `lsr-text-catalog` npm `0.2`. Upgrade both packages together and regenerate; PHP-only consumers are unaffected. Application steps are in the npm package's [upgrade guide](https://github.com/Heroyt/lsr-text-catalog-js#upgrading-from-01-artifact-format-1).

## Requirements

- PHP `>=8.5`, `ext-dom`, `ext-libxml`, `nette/neon ^3.4`, and `gettext/gettext ^5.7`.
- No mandatory LSR framework, container, native gettext, Node, Vue, Redis, or application bootstrap dependency.
- Optional DI: install `nette/di ^3.2`.
- Optional command: install `symfony/console ^7.4 || ^8.0`; `lsr/console ^0.2` can discover the registered service.
- Optional translation adapter: install [`lsr/translations ^0.1`](https://github.com/Heroyt/lsr-translations) (requires `ext-gettext`); the application, its framework adapter or `lsr/translations` configures locale, domain binding and encoding.
- Optional Laravel integration: `laravel/framework ^12.0 || ^13.0`; older Laravel versions conflict.

For local package development, use a Composer `path` repository with `options.symlink: true` and an explicit development version constraint. Consumer applications should normally use released packages rather than local links.

## Standalone compilation

```php
use Lsr\TextCatalog\CatalogConfig;
use Lsr\TextCatalog\TextCatalog;
use Lsr\TextCatalog\TextCatalogCompiler;
use Lsr\TextCatalog\TextCatalogLoader;

$root = __DIR__;
$config = new CatalogConfig(
    sourceDirectory: $root . '/copy',
    cacheFile: $root . '/var/catalog.php',
    languageDirectory: $root . '/languages',
    sourceRoot: $root,
    domain: 'example',
    locales: ['en_US'], // bootstrap with the source locale; add target locales after preparing their PO files
    sourceLocale: 'en_US',
    frontendDirectory: $root . '/generated', // null for PHP-only consumers
    // potFile: $root . '/languages/example.pot', // this is the default
);
$loader = new TextCatalogLoader($config->sourceDirectory);
$result = new TextCatalogCompiler($loader, $config)->compile();

$catalog = new TextCatalog($loader, $config->cacheFile, useCompiledCache: true);
echo $catalog->text('example.title');
```

A source file such as `copy/example.neon`:

```neon
example:
    title: 'Welcome %{name}'
    count:
        one: '%{count} item'
        plural: '%{count} items'
    rich:
        html: '<strong>%{name}</strong>'
```

Keys combine the relative directory/file namespace with nested NEON keys. Directory names and file basenames use camelCase; each file contains exactly one root matching its basename (`example.neon` → `example`). Source text is nonempty and validated; rich text requires an explicit `html` leaf, and plural groups use sibling `one`/`plural` leaves with matching placeholders. `html`, `one`, and `plural` are structural names, not ordinary nested key segments.

`TextCatalogDefinition` exposes `texts`, `htmlKeys`, `plurals`, and `sourceFiles`. `TextCatalog` exposes source lookup and HTML membership; it never translates, interpolates, checks freshness, or compiles during lookup. It memoizes the selected definition. Compiled PHP caches are trusted executable build output. Compile during deployment, and restart/reset application-owned service lifetimes as needed when replacing a catalog.

`CompilationResult` contains the definition and, when frontend generation is enabled, the generation digest and manifest path, plus `staleCleanupFailures` (a list of stale `texts/*.ts` paths that could not be removed; empty by default). Compilation maintains POT/PO catalogs and emits MO, PHP cache, and optional frontend artifacts. Translation contexts are semantic keys; plural contexts are the parent key. Existing translator content is merged rather than replaced with empty translations. Every configured locale must have complete, non-fuzzy active translations, including all required plural forms, and each translation must preserve the source placeholder set. The source locale is populated from canonical NEON copy.

Bootstrap with only the source locale to generate the POT, then prepare complete translator-owned target PO files before adding their locales to `locales`. Missing or fuzzy translations, missing plural forms and placeholder mismatches fail compilation before any outputs are published; a failed compile does not publish a starter target PO.

All content is validated and rendered before publication. Outputs are staged, the manifest is replaced last, and handled publication failures restore replaced outputs. Do not treat this as a distributed transaction across processes or machines; npm validates artifact digests and rejects an incomplete generation.

## Frontend artifact contract

With `frontendDirectory` enabled, compilation emits artifact **format 2**. Texts and translations are split per *group* (the root catalog key, i.e. the part of a key before its first `.`; a key without a dot is its own group) and per locale, so applications load only what they render:

- `catalog.ts`: `TextKey`, `HtmlTextKey`, `TextGroup`, HTML membership, locale configuration and `catalogGroups`, a map of lazy `import()` loaders for each group's source texts and per-locale translations.
- `catalog.compiled.ts`: the same types, locale configuration and `catalogGroups` without source loaders, plus a declaration-only `text` macro.
- `texts/<group>.ts`: runtime source texts of one group. Keys are stored **relative to the group**: key `game.count.one` in group `game` is stored as `count.one`, and a root leaf whose key equals its group (key `title` in group `title`) is stored under the empty string `""`. Consumers re-add the group prefix (`<group>.<key>`, or just `<group>` for `""`) to recover the semantic key.
- `texts/<group>.<locale>.ts`: contextual translations of one group for one **non-source** locale. The source locale ships no translation modules because the gettext msgid already is the source text.
- `catalog.build.json`: format version **2**, configuration identity, source/HTML/plural snapshot, SHA-256 digests of every artifact (including each group module under `artifacts.groups`) and a deterministic generation digest. It is published last as the commit marker.

Generated modules load `texts/*` through dynamic `import()` (written with `.js` specifiers that TypeScript and Vite resolve to the generated `.ts` files), so bundlers emit them as separate lazy chunks. Groups are camelCase and never contain `.`, so `<group>.ts` and `<group>.<locale>.ts` cannot collide. Locale identifiers may contain `.` and `@` (for example `sr@latin`, `de_DE.UTF-8`); they are used verbatim in file names, loader specifiers and manifest entries. Compilation fails without publishing anything when two generated `texts/` paths differ only by letter case (for example groups `fooBar` and `foobar`, or locales `cs_CZ` and `cs_cz`), because consumers may run on case-insensitive filesystems.

Publication skips every output whose existing bytes are identical (its mtime and inode stay untouched), so recompiling an unchanged catalog, or changing one group, only rewrites the affected files plus the manifest and catalog modules as needed. When a previously published file differs from the new name only by letter case (for example after renaming group `fooBar` to `foobar`), the old entry is replaced so the file carries the exact new name even on case-insensitive filesystems. After a successful publish, stale `texts/*.ts` files from removed groups or locales are deleted on a best-effort basis: only regular `.ts` files directly inside `texts/` are touched, a file that is the new generation's file under another letter case is never deleted, and a failing deletion does not fail compilation. Paths that could not be removed are returned in `CompilationResult::$staleCleanupFailures`, and `texts:cache:compile` prints a warning line per path while still exiting successfully.

Generated types import `lsr-text-catalog/types`; generated files belong to the consumer. They are ordinary TypeScript files visible to `tsc`/`vue-tsc` before Vite runs. Declaration-only compiled macros require the compiled Vite transform; they are not callable JavaScript fallbacks. Regenerate frontend artifacts when upgrading the compiler or npm package rather than hand-editing generated import paths.

Generation hashing uses recursively key-sorted JSON with compact separators and unescaped Unicode, slashes and line terminators, excluding `generation` itself. Artifact digests cover exact bytes. The npm reader accepts format 2 and rejects missing/unknown versions (including the former format 1), mismatched configuration, malformed references, tampering and mixed generations. Package versions remain independent of the artifact format.

## Optional DI and console integration

```neon
extensions:
    textCatalog: Lsr\TextCatalog\Di\TextCatalogExtension

textCatalog:
    sourceDirectory: %appDir%/copy
    cacheFile: %appDir%/var/catalog.php
    languageDirectory: %appDir%/languages
    sourceRoot: %appDir%
    domain: example
    locales: [en_US, cs_CZ]
    sourceLocale: en_US
    frontendDirectory: %appDir%/generated
    useCompiledCache: true
    command: true
    translator: true
    sanitizer: @htmlSanitizer
```

The extension registers the same config, loader, lookup and compiler services. `command: true` adds `Lsr\TextCatalog\Console\CompileTextCatalogCommand`, named **`texts:cache:compile`**. Register it with Symfony Console directly or let `lsr/console` discover it in the consumer container. The consumer owns its actual `bin/console`; the package does not bootstrap an application. Command descriptions and diagnostics require no catalog keys. Compilation errors produce a failure exit status.

`translator: true` (requires `lsr/translations`) registers `TextTranslator` as `textCatalog.translator`. It uses the container's `Lsr\Translations\MessageTranslator` service when one exists and otherwise registers a native `GettextTranslator` as `textCatalog.messageTranslator`. `sanitizer` is an optional service reference or statement for the HTML sanitizer. In LSR applications, add the catalog domain to Core's `translations.domains` and keep `languageDirectory` equal to `LANGUAGE_DIR` so Core binds the domain whenever it switches the language.

## Translation and HTML

`Lsr\TextCatalog\Translation\TextTranslator` is an injected adapter, not a global helper autoload. It translates through any [`lsr/translations`](https://github.com/Heroyt/lsr-translations) `MessageTranslator`:

```php
$translator = new Lsr\TextCatalog\Translation\TextTranslator(
    $catalog,
    new Lsr\Translations\GettextTranslator('example'),
    domain: 'example',
    sanitizeHtml: $applicationSanitizer,
);

$label = $translator->langText('example.title', format: ['name' => 'Ada']);
$count = $translator->langText(
    'example.count.one', 'example.count.plural', 3, ['count' => 3],
);
$html = $translator->langHtmlText('example.rich', ['name' => $untrustedName]);
```

The semantic key is the gettext context; plural keys use their parent key. The native translator reads the process locale: select it with `Lsr\Translations\Localization`, a framework adapter, or the application's own `setlocale`/`bindtextdomain` setup, and bind the catalog domain to `languageDirectory`. Named `%{name}` interpolation and numeric `sprintf` arguments are supported; mixed numeric/named argument sets are rejected. Plain strings remain unescaped data: use the consumer's normal escaped rendering.

HTML eligibility is checked, translation/interpolation runs, and **then** the application sanitizer processes the final output. Missing sanitizer rejects HTML calls. Source HTML validation is not a replacement for output sanitization. The package supplies no no-op sanitizer or application-specific policy. Native gettext locale/domain state is process-global: long-running applications must serialize/reset it appropriately; constructing this adapter does not isolate concurrent native locale changes.

Verify native locale reset on the deployment platform. On the macOS verification host, changing `setlocale` and environment variables alone retained a cached translation; explicitly re-selecting the default `textdomain` invalidated that native cache in the sequential-request smoke check. `lsr/translations` does this on every locale switch; it is still not a per-request isolation guarantee for concurrent requests in one process. Use isolated workers when native global state cannot be reset safely.

## Laravel

Laravel 12 and 13 discover `Lsr\TextCatalog\Laravel\TextCatalogServiceProvider` automatically. Publish and adjust the configuration:

```sh
php artisan vendor:publish --tag=text-catalog-config
```

| Key | Default |
| --- | --- |
| `source_directory` | `resource_path('text-catalog')` |
| `cache_file` | `base_path('bootstrap/cache/text-catalog.php')` |
| `language_directory` | `lang_path('gettext')` |
| `source_root` | `base_path()` |
| `domain` | `catalog` |
| `locales` / `source_locale` | `['en_US']` / `en_US` |
| `frontend_directory` / `pot_file` | `null` |
| `use_compiled_cache` | `true` |
| `sanitizer` | `null`, or a container-resolvable callable class/binding |

The provider registers the catalog services and the Artisan command:

```sh
php artisan texts:cache:compile
```

Compile during the build or deployment pipeline, not during requests. A Vite `compile()` callback can run `php artisan texts:cache:compile`.

With `lsr/translations` installed (its provider mirrors Laravel's locale into gettext), the provider also binds `TextTranslator`, binds the catalog domain to `language_directory` on every locale switch, and adds Blade directives:

```blade
<h1>@langText('example.title', format: ['name' => $user->name])</h1>
<p>@langText('example.count.one', 'example.count.plural', $count, ['count' => $count])</p>
<div>@langHtmlText('example.rich', ['name' => $user->name])</div>
```

`@langText` escapes its output. `@langHtmlText` echoes the configured sanitizer's output raw and fails without a sanitizer. Add every target locale to both `text-catalog.locales` and `lsr-translations.supported_languages`.

## Development and verification

```sh
composer install
composer validate --strict --no-check-publish
composer phpstan
composer test
composer cs
composer cs:fix # applies formatting; composer cbf is the conventional alias
```

Coding standards are defined in [.php-cs-fixer.php](.php-cs-fixer.php), covering `src`, `tests`, and the configuration itself. PHPStan runs at level 8. Tests use synthetic catalogs, translations, directories and containers.

## Agent skill

Use the [lsr-text-catalog skill](https://github.com/Heroyt/lsr-skills/tree/master/skills/lsr-text-catalog) for source-copy ownership, compiler/build integration, translation adapters and the optional Vue/Vite companion.

```sh
npx skills add Heroyt/lsr-skills --skill lsr-text-catalog
```

The skill is guidance, not a runtime dependency; installing it does not install or publish either package.

## License and maintenance

[MIT](LICENSE), copyright (c) 2026 Tomáš Vojík. Source and issue tracking: [Heroyt/lsr-text-catalog](https://github.com/Heroyt/lsr-text-catalog). Composer manifest versions match their Git release tags; they are independent of npm versions and artifact format versions.

Packagist registration and distribution through the LSR Satis repository are separate release operations. Creating a Git tag does not publish metadata to either channel.
