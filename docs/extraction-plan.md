# Text catalog extraction record and release gates

Status on **2026-09-08**: implementation extracted into both independent packages, committed incrementally, and verified through local links and installed archives. Public-distribution preparation adds the PHP repository to the workspace Satis configuration and prepares npm metadata for public access. **No release tag, push, registry publication, Satis build/upload or application migration has occurred.** Consumer READMEs describe the intended public Packagist/npm installation contract, not evidence of completed registry registration.

## Scope and authority

- PHP: [`lsr/text-catalog`](../README.md), namespace `Lsr\TextCatalog\`, origin [Heroyt/lsr-text-catalog](https://github.com/Heroyt/lsr-text-catalog).
- npm: [`@lsr/text-catalog`](https://github.com/Heroyt/lsr-text-catalog-js), origin [Heroyt/lsr-text-catalog-js](https://github.com/Heroyt/lsr-text-catalog-js).
- These are independent Git repositories and versioned packages, not a shared release train. npm `0.1.0` is prepared for public publication on npmjs.org; the private flag is removed. Composer release versions remain derived from Git tags. Configured GitHub origins do not establish registry ownership.
- The user authorized extraction, incremental commits, public dependency downloads, MIT licensing, Satis configuration and npm publication preparation, and public-facing installation documentation. Publication itself is explicitly excluded. Copyright: 2026 Tomáš Vojík. The reference application's license remains unchanged.
- Read-only reference: `/Users/heroyt/Projects/code-hunt-game`, revision **`4aa85bce33f6846c0213f62574e779040cd01d23`**, default branch `master`.
- [ADR 0007](https://github.com/eSoul-cz/code-hunt-game/blob/master/docs/adr/0007-neon-source-copy-catalog-with-gettext.md) remains the behavioral specification. This record describes package extraction, not a second authoring format.
- Application copy, editable translations, locale/domain choices, HTML policy, bootstrap/Inertia wiring and deployment remain consumer-owned. Only synthetic copy was used in package tests and disposable consumers. No application source, lockfile or dependency constraint was migrated.

## Extracted seams

| Reference seam | Package responsibility |
| --- | --- |
| `src/Core/TextCatalogDefinition.php`, `TextCatalogLoader.php`, `TextCatalog.php` | Standalone definition/loading/source lookup under `Lsr\TextCatalog`; explicit paths and cache policy, no application constants or implicit compilation. |
| `src/Core/TextCatalogCompiler.php` | Typed `CatalogConfig`/`CompilationResult`, explicit locale/domain/source-reference/output configuration, optional frontend output and versioned publication. |
| `include/functions.php`, `config/di/configs/texts.neon`, `src/Console/Commands/CompileTextCatalogCommand.php` | Optional `TextTranslator`, `TextCatalogExtension`, and `CompileTextCatalogCommand`; no global helper autoload or game-key command metadata. |
| `vite/textCatalog.ts` | `/vite` compiler callback, coherent generation, failure barriers, client/SSR/custom-environment invalidation and build-watch lifecycle. |
| `vite/textCatalogTransform.ts`, `textCatalogTypes.ts` | Resolver-based configured-facade recognition, literal expansion, lexical/evaluation-order safety, source maps and explicit diagnostics. |
| `assets/js/productCopy.ts`, `productCopyCompiled.ts`, `productCopyLanguage.ts` | `/runtime`, `/compiled` and `/types`; consumer-owned generated data and isolated app/request gettext state. |
| PHP and frontend product-copy sanitizers | Policy and concrete sanitizers remain in consumers. Package HTML helpers require a callback and sanitize final translated/interpolated output. |

Detailed construction/configuration examples are in the [PHP README](../README.md) and [npm README](https://github.com/Heroyt/lsr-text-catalog-js/blob/master/README.md).

## Implemented interfaces

### PHP

- `CatalogConfig(sourceDirectory, cacheFile, languageDirectory, sourceRoot, domain, locales, sourceLocale, frontendDirectory = null, potFile = null)` validates explicit local paths and identity. The default POT destination is `languageDirectory/domain.pot`.
- `TextCatalogLoader(directory)->load()` validates recursive NEON catalog namespaces, source text, placeholder pairs and permitted source HTML.
- `TextCatalog(loader, cacheFile, useCompiledCache)` supplies canonical source lookup/HTML membership. It memoizes the selected definition; compilation and deployment freshness are explicit host actions. Compiled PHP is trusted executable build output.
- `TextCatalogCompiler(loader, config)->compile()` maintains POT/PO, emits MO and PHP source cache, and optionally emits frontend artifacts. `CompilationResult` exposes `definition`, nullable `generation` and nullable `manifestFile`.
- `Di\TextCatalogExtension` opts into Nette service registration. `command: true` registers `Console\CompileTextCatalogCommand`, named `texts:cache:compile`, for Symfony or LSR command discovery. Consumers retain their real console entrypoint.
- `Translation\TextTranslator` adapts native contextual/plural gettext and interpolation. It requires native gettext only when constructed; locale/domain/encoding setup and long-running process reset are application responsibilities.

The standalone package has no hard LSR, Nette DI, Symfony Console, Node, Vue, Redis or application bootstrap dependency. Frontend generation can be disabled. HTML rendering has no built-in no-op sanitizer and no application-specific sanitization dependency.

### npm

- ESM/declaration exports: `/runtime`, `/compiled`, `/types`, `/vite`.
- `createRuntimeCatalog` receives generated runtime data and language/sanitizer options; returns typed source/translated/plural/HTML helpers plus `createCatalog` and `installCatalog`.
- `createCompiledCatalog` receives lookup-free generated data and language/sanitizer options; returns `pgettext`, `npgettext`, `htmlPgettext`, `createCatalog`, and `installCatalog`.
- Each app/request gets a new instance. Nested translations/plural arrays are cloned. Facades have separate injection keys; outside-app fallback is fixed to the source language and never exposes a mutable installed instance.
- `textCatalog` defaults to runtime mode. Compiled mode is explicit and requires its companion facade. Root, source/language/frontend paths, facade files, identity and `compile(root)` are host inputs; no game command/environment/alias is assumed.
- Consumer-local generated files provide key unions before Vite runs. Unknown keys, plural-key misuse, invalid `HtmlTextKey` assignments and cross-catalog key assignments fail standalone typechecking. Runtime HTML calls also check membership; compiled HTML macros reject ineligible keys during transformation.
- Runtime mode supports dynamic typed keys. Compiled mode expands only supported literal authoring forms, rejects escapes instead of silently falling back, and excludes the runtime source map/adapter from emitted chunks.

## PHP-to-npm artifact contract: version 1

The reference's unversioned snapshot was not retrospectively declared versioned. The extracted producer and reader implement a new explicit interoperability boundary:

| Manifest field | Contract |
| --- | --- |
| `formatVersion` | Integer `1`. Missing/unknown versions are rejected, not guessed or shimmed. |
| `generation` | SHA-256 of the canonical manifest payload excluding `generation`; not a package version or timestamp. |
| `sourceLocale`, `locales`, `domain` | Explicit identity checked against Vite configuration. Locales are nonempty, unique and include the source locale. |
| `artifacts.runtime`, `artifacts.compiled` | Relative `catalog.ts` / `catalog.compiled.ts` paths and SHA-256 digests of their exact bytes. |
| `texts`, `htmlKeys`, `plurals` | Validated source snapshot; HTML and plural references resolve to known text keys. |

Canonical JSON recursively sorts object keys, preserves array order, uses compact separators, and does not escape Unicode, slashes or line terminators. Artifact hashes are computed first; generation hashing is not circular. Generated authoring declarations are in the two TypeScript artifacts rather than separate declaration files.

PHP validates/renders the complete generation before publication, stages outputs, publishes the manifest last and restores replaced outputs on handled publication failure. npm validates version, shape, identity and all artifact bytes before exposing a generation, and rechecks input stability after compilation. Partial/mixed/failed generations block reads/builds instead of leaking the last good snapshot. Manifest-last publication is not a distributed filesystem transaction or a crash-recovery guarantee.

Optional additive fields may remain format 1 only when old readers can safely ignore them. Changed required fields, generated exports or semantics require a new format. Producer/reader package releases stay independent and must state their supported format versions. No legacy-format alias or compatibility shim was added.

## Ordered work: outcome

1. **Extraction prerequisites and support contracts — completed locally.** User authorization, MIT license, existing origins, pinned reference and package contracts recorded. Registry/scope ownership is still a publication prerequisite, not inferred from Git remotes.
2. **Standalone PHP and contract v1 — implemented and verified.** Direct runtime dependencies only; synthetic validation/publication coverage; compilation without LSR or npm demonstrated from an installed archive.
3. **npm helpers and Vite reader/transform — implemented and verified.** Real ESM/declarations, explicit compiler/facade seams, isolated Vue state and both modes. Prebuilt npm SSR/exports worked with an empty executable `PATH`.
4. **Disposable local dependency consumer — verified.** Composer symlinked `path` packages and pnpm `link:` consumed the working trees outside the reference app. The consumer owned a synthetic catalog, English/Czech translations, sanitizer, Vue/SSR bootstrap and CLI.
5. **Optional LSR integration — verified.** Real Nette container compilation and `lsr/console` discovery listed/executed `texts:cache:compile`; invalid translations returned status 1 without overwriting the good manifest.
6. **Installed archive consumer — verified.** Fresh external consumer used a Composer ZIP and npm tarball, with no package path/link dependency. A local development-snapshot package repository supplied Composer metadata plus the archive URL; this was not a release version. Optional `lsr/console 0.2.0` was also consumed as a ZIP of its existing committed source.
7. **Public distribution prepared; publication gated.** The workspace `satis.json` includes the `lsr/text-catalog` VCS repository without running a build or upload. npm metadata targets public npmjs.org access and prepares version `0.1.0`; the tarball includes TypeScript source files for its source maps. Packagist registration is separate from Satis configuration. Registry/scope verification, release authorization and published-package verification remain required. No push, tag, Satis upload or npm publication is included here.
8. **Application migration — gated, not performed.** Select each app and version independently in a later authorized task. Replace implementations and all callers cleanly while retaining app copy/configuration; test published packages and remove temporary links. Do not infer compatibility or upgrade LaserArenaControl/LaserLiga from this extraction.

## Verification record

Environment: macOS arm64; PHP **8.5.10**, Composer **2.10.3**, Node **26.8.1**, pnpm **11.25.0**. Minimum-Node smoke additionally used **20.19.0**.

Installed PHP dependencies: gettext/gettext **5.7.3**, nette/neon **3.4.8**, nette/di **3.2.7**, Symfony Console **8.1.6**; archived optional integration also passed with Symfony Console **7.4.18**. Tooling: PHP CS Fixer **3.95.24**, PHPStan **2.2.13**, PHPUnit **13.3.2**.

Installed frontend dependencies: Vue/compiler packages **3.5.42**, vue3-gettext **4.0.1**, Vite **8.2.2**, Vue Vite plugin **6.0.8**, TypeScript **6.0.3**, vue-tsc **3.3.11**, Vitest **4.1.11**. The external application supplied DOMPurify **3.4.15** and jsdom **30.0.1**. These observed versions are not a claim that every version inside each declared range was exercised.

| Surface | Observed proof |
| --- | --- |
| PHP package | `composer validate --strict --no-check-publish`, `composer phpstan`, `composer test`, `composer cs`: passed; PHPUnit **64 tests / 141 assertions**, PHPStan level 8. `composer cs:fix` / `composer cbf` uses PHP CS Fixer. Exact rule arrays and risky-fix policy matched the reference config; only Finder paths differ. |
| npm package | `pnpm run build`, `pnpm run typecheck`, `pnpm test`, formatting: passed; **106 tests** across manifest/runtime/transform/Vite suites. |
| Independent packaging | `composer archive --format=zip` and `npm pack` built actual archives. PHP archive exclusions remove vendor, lock/cache/temp/build/editor/environment files; npm allowlist ships built exports, manifest, README and MIT license. No game copy or sibling runtime path is required. |
| Standalone archive PHP | Installed only text-catalog, gettext/gettext, gettext/languages and nette/neon; real compilation passed with no Nette DI, Symfony Console, LSR, npm dependencies or source symlink. |
| Prebuilt archive npm | All four exports imported; runtime SSR interleaved Czech/English/Czech requests with an empty executable `PATH`. No PHP invocation was needed. |
| Configurable compilation | External nondefault paths, `external` domain and en_US/cs_CZ locales produced PHP cache, POT/PO/MO, runtime/compiled TS and a validated v1 manifest. Package tests cover merge preservation, malformed source/translation and publication rollback. |
| Generated authoring types | Standalone `vue-tsc --noEmit` passed before Vite. Deliberately invalid source/plural/HTML-type/cross-catalog assignments produced the expected TypeScript errors; removing them restored the pass. A second generated catalog used a distinct `secondary.heading` key union. |
| Runtime SSR/hydration | Archive-installed production build and SSR rendered `Ahoj Ada`, contextual `Vítej Ada`, and `3 položky`. Browser hydration plus a click changed the count to `4 položky`; sanitized HTML was `<em>Ada</em>`. No browser warning/error or hydration mismatch was observed. |
| Compiled SSR/hydration | Archive-installed explicit compiled client/SSR builds passed, also using Node 20.19.0 for Vite. Browser interaction changed `3 položky` to `5 položek`; title/context/HTML matched. Emitted chunk module membership included compiled data/adapter and excluded runtime data/adapter. |
| Dev HMR and failure recovery | PO edit changed unchanged browser callers from `Ahoj Ada` to `Nazdar Ada`; ordinary Vue template HMR retained count state. Invalid placeholder translation returned HTTP 500 and retained manifest bytes; repair restored the page. Archive-installed Vite additionally refreshed evaluated SSR and client transforms, rejected failed generations on both surfaces and recovered after HMR notification. |
| Build watch and structure | Real Vite/Rolldown tests cover nested file/directory creation/deletion, input-root recreation, source-watcher replacement, mid-build edits, convergence without output loops and output-directory cleanup. Review's missing-output-directory case was reproduced as ENOENT, fixed by recreating the marker directory, and passed afterward; retry callbacks report errors rather than escaping the timer. |
| Translation and HTML safety | Context collisions, locale-specific plural forms, placeholders, missing sanitizer and sanitizer-after-interpolation are covered by package tests and external PHP/browser/SSR scenarios. Native archived helpers passed sequential Czech/C/Czech requests with explicit host reset and final-output sanitization. |
| App/request isolation | Package tests exercise independently installed apps, mutable nested translation isolation, sanitizer isolation, interleaved SSR and fixed outside-app fallback. External prebuilt SSR confirmed Czech/English/Czech isolation. Native PHP global-state limits are explicit below. |
| Artifact compatibility | Tests accept v1 and reject missing/unknown versions, malformed references, identity mismatch, missing/tampered bytes and mixed generations before transformation. External PHP-produced v1 artifacts were consumed in both modes from installed archives. |
| Optional framework range | Archived `lsr/console 0.2.0` + Nette DI 3.2.7 discovered/executed the command with Symfony Console 7.4.18 and 8.1.6. Failure returned 1 and preserved the last valid manifest; repaired input compiled successfully. |
| Minimum Node | Node 20.19.0 imported the packed Vite export, rendered runtime and compiled Vue SSR with contextual translations, and built the compiled external client/SSR bundle. |
| Review | CodeRabbit reviewed both source extractions. PHP's initial finding was stale skeleton documentation, replaced here and in the README; its follow-up review was blocked by the free CLI review quota, not reported clean. npm's watcher finding was reproduced/fixed; its second full review, including tests and README, reported **zero findings**. |

### Known platform/dependency observations

- **Native gettext is process-global.** On this macOS host, `setlocale` plus environment changes alone left an old translation cached. Changing the default `textdomain` invalidated the native cache in the sequential-request smoke check. The adapter adds no mutable locale cache, but it cannot provide concurrent native locale isolation. Real deployments must verify reset/domain binding or isolate workers; do not copy this smoke setup as an untested cross-platform recipe.
- **Upstream browser-build warning:** Vite externalized `fs` from `pofile 1.1.4`, reached through the required `vue3-gettext 4.0.1` peer. This is not a Node plugin import from a package browser entry. Both production modes and browser hydration worked without runtime console errors. Recheck this upstream warning when selecting release peer versions.
- Full semver/platform matrices and actual published-registry installation remain release work. The local archive gate is complete; it does not authorize publication or establish registry ownership.

## Remaining authorized-release gate

Before publishing either package, confirm registry identity/credentials, select the PHP release tag and confirm the prepared npm version, rebuild archives from the selected committed revisions, rerun affected package/consumer checks, and review the platform observations above. Register the PHP repository on Packagist separately from any authorized Satis build/upload. Publish only the explicitly authorized package(s). Install the published versions in an external consumer and repeat the gate, then consider separately authorized app migrations. Neither app's current framework constraints should be tightened or advanced merely to match the reference application.

The original preparation checks on 2026-09-08 only validated skeleton metadata and a dry-run npm file list. The implementation, archive, CLI, browser, SSR and lifecycle evidence above supersedes those preparation-only checks.
