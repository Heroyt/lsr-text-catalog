# LSR Text Catalog

`lsr/text-catalog` provides standalone NEON source-copy loading, source lookup, gettext compilation, and optional Nette/Symfony Console integration. Namespace: `Lsr\TextCatalog\`. The implementation is extracted and locally verified; it is **not released or published**.

The behavioral reference is [code-hunt-game ADR 0007](https://github.com/eSoul-cz/code-hunt-game/blob/master/docs/adr/0007-neon-source-copy-catalog-with-gettext.md), reviewed at `4aa85bce33f6846c0213f62574e779040cd01d23`. The reference application was not modified. See the [extraction record and release gates](docs/extraction-plan.md) and the independently versioned [JavaScript package](https://github.com/Heroyt/lsr-text-catalog-js).

## Requirements

- PHP `>=8.5`, `ext-dom`, `ext-libxml`, `nette/neon ^3.4`, and `gettext/gettext ^5.7`.
- No mandatory LSR framework, container, native gettext, Node, Vue, Redis, or application bootstrap dependency.
- Optional DI: install `nette/di ^3.2`.
- Optional command: install `symfony/console ^7.4 || ^8.0`; `lsr/console ^0.2` can discover the registered service.
- Optional native translation adapter: enable `ext-gettext`; the application configures locale, domain binding and encoding.

For local development, use a Composer `path` repository with `options.symlink: true` and an explicit development version constraint. Nothing in this repository installs the package into an application or publishes it to Satis.

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
    locales: ['en_US', 'cs_CZ'],
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

`CompilationResult` contains the definition and, when frontend generation is enabled, the generation digest and manifest path. Compilation maintains POT/PO catalogs and emits MO, PHP cache, and optional frontend artifacts. Translation contexts are semantic keys; plural contexts are the parent key. Existing translator content is merged rather than replaced with empty translations. Nonempty translations must preserve source placeholders.

All content is validated and rendered before publication. Outputs are staged, the manifest is replaced last, and handled publication failures restore replaced outputs. Do not treat this as a distributed transaction across processes or machines; npm validates artifact digests and rejects an incomplete generation.

## Frontend artifact contract

With `frontendDirectory` enabled, compilation emits:

- `catalog.ts`: source lookup map, `TextKey`, `HtmlTextKey`, HTML membership, locale configuration and contextual translations.
- `catalog.compiled.ts`: authoring key types, a declaration-only `text` macro, locale configuration and translations, without a runtime source lookup map.
- `catalog.build.json`: format version **1**, configuration identity, source/HTML/plural snapshot, SHA-256 artifact digests and a deterministic generation digest.

Generated types import `@lsr/text-catalog/types`; generated files belong to the consumer. They are ordinary TypeScript files visible to `tsc`/`vue-tsc` before Vite runs. Declaration-only compiled macros require the compiled Vite transform; they are not callable JavaScript fallbacks.

Generation hashing uses recursively key-sorted JSON with compact separators and unescaped Unicode, slashes and line terminators, excluding `generation` itself. Artifact digests cover exact bytes. The npm reader accepts format 1 and rejects missing/unknown versions, mismatched configuration, malformed references, tampering and mixed generations. Package versions remain independent of the artifact format.

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
```

The extension registers the same config, loader, lookup and compiler services. `command: true` adds `Lsr\TextCatalog\Console\CompileTextCatalogCommand`, named **`texts:cache:compile`**. Register it with Symfony Console directly or let `lsr/console` discover it in the consumer container. The consumer owns its actual `bin/console`; the package does not bootstrap an application. Command descriptions and diagnostics require no catalog keys. Compilation errors produce a failure exit status.

## Native translation and HTML

`Lsr\TextCatalog\Translation\TextTranslator` is an injected adapter, not a global helper autoload:

```php
$translator = new Lsr\TextCatalog\Translation\TextTranslator(
    $catalog,
    domain: 'example',
    sanitizeHtml: $applicationSanitizer,
);

$label = $translator->langText('example.title', format: ['name' => 'Ada']);
$count = $translator->langText(
    'example.count.one', 'example.count.plural', 3, ['count' => 3],
);
$html = $translator->langHtmlText('example.rich', ['name' => $untrustedName]);
```

Configure native gettext (`setlocale`, environment where required, `bindtextdomain`, encoding) in application bootstrap. Named `%{name}` interpolation and numeric `sprintf` arguments are supported; mixed numeric/named argument sets are rejected. Plain strings remain unescaped data: use the consumer's normal escaped rendering.

HTML eligibility is checked, translation/interpolation runs, and **then** the application sanitizer processes the final output. Missing sanitizer rejects HTML calls. Source HTML validation is not a replacement for output sanitization. The package supplies no no-op sanitizer or application-specific policy. Native gettext locale/domain state is process-global: long-running applications must serialize/reset it appropriately; constructing this adapter does not isolate concurrent native locale changes.

Verify native locale reset on the deployment platform. On the macOS verification host, changing `setlocale` and environment variables alone retained a cached translation; explicitly changing the default `textdomain` invalidated that native cache in the sequential-request smoke check. This is application/process setup, not a portable per-request isolation guarantee supplied by the adapter. Use isolated workers when native global state cannot be reset safely.

## Development and verification

```sh
composer install
composer validate --strict --no-check-publish
composer phpstan
composer test
composer cs
composer cs:fix # applies formatting; composer cbf is the conventional alias
```

PHP CS Fixer replaces the sibling packages' coding-standard tooling here. [.php-cs-fixer.php](.php-cs-fixer.php) uses the reference application's exact rules and risky-fix policy, with only Finder paths adapted to this package (`src`, `tests`, and the configuration itself). PHPStan runs at level 8. Tests use synthetic catalogs, translations, directories and containers, not application copy.

The [extraction record](docs/extraction-plan.md) records package and external-consumer evidence, tested dependency versions and remaining release gates.

## License and publication

[MIT](LICENSE), copyright (c) 2026 Tomáš Vojík. Extraction and package licensing were authorized; the reference application's license remains unchanged. Origin: [Heroyt/lsr-text-catalog](https://github.com/Heroyt/lsr-text-catalog). Registry ownership, release versions, tags, push and Satis publication require a separately authorized release. No application migration is included.
