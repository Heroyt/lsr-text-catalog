# Text catalog extraction plan

Status: preparation only. No implementation has been extracted, no public interface is available, and no package has been released. This plan describes future authorized work, not behavior supplied by the skeletons.

## Scope and authority

- PHP skeleton: [`lsr/text-catalog`](../README.md), directory `lsr-text-catalog`, intended namespace `Lsr\TextCatalog\`.
- npm skeleton: [`@lsr/text-catalog`](https://github.com/Heroyt/lsr-text-catalog-js), directory `lsr-text-catalog-js`.
- These are separate package/repository roots with independent versions, not one release train. The destination parent is not a Git repository. The user initialized both package repositories and configured origins [Heroyt/lsr-text-catalog](https://github.com/Heroyt/lsr-text-catalog) and [Heroyt/lsr-text-catalog-js](https://github.com/Heroyt/lsr-text-catalog-js); preserve and use those remotes.
- Package names are candidates following local naming conventions. Registry availability, npm scope control and publication credentials remain unverified; configured GitHub origins do not establish registry ownership. Do not add these skeletons to Satis.
- [Reference application](https://github.com/eSoul-cz/code-hunt-game), local checkout `/Users/heroyt/Projects/code-hunt-game`, is read-only in this task.
- [ADR 0007](https://github.com/eSoul-cz/code-hunt-game/blob/master/docs/adr/0007-neon-source-copy-catalog-with-gettext.md) is the canonical authoring, gettext, compilation-mode and lifecycle specification. Preserve that specification; this document records extraction work, not a replacement catalog format. Repository links use the reference's default branch, `master`; pin the reviewed source revision before extraction.
- Application copy, editable translations, locale/domain choice, sanitization policy, Inertia/bootstrap wiring and deployment configuration remain application-owned. Moving callers or removing application implementations requires a later, explicit migration authorization.

Both packages use the MIT license by explicit user approval, matching existing LSR sibling packages: [PHP license](../LICENSE) and [npm license](https://github.com/Heroyt/lsr-text-catalog-js/blob/master/LICENSE), copyright (c) 2026 Tomáš Vojík. The reference application's proprietary licensing remains unchanged. Package licensing does not authorize extraction or publication; confirm compatible redistribution rights for implementation and fixtures before importing them. Do not copy the game's catalog content or full dependency set.

## Reference seams

Paths below link to evidence, not files to copy wholesale:

| Seam | Reference | Extraction work |
| --- | --- | --- |
| PHP catalog | [TextCatalog.php](https://github.com/eSoul-cz/code-hunt-game/blob/master/src/Core/TextCatalog.php), [TextCatalogDefinition.php](https://github.com/eSoul-cz/code-hunt-game/blob/master/src/Core/TextCatalogDefinition.php), [TextCatalogLoader.php](https://github.com/eSoul-cz/code-hunt-game/blob/master/src/Core/TextCatalogLoader.php) | Inject source/cache paths and configuration; preserve source lookup and validation semantics without application constants. |
| Artifact producer | [TextCatalogCompiler.php](https://github.com/eSoul-cz/code-hunt-game/blob/master/src/Core/TextCatalogCompiler.php) | Replace `ROOT` source-reference coupling; make output paths, locales and domain explicit; version the cross-package artifacts. |
| PHP integration | [functions.php](https://github.com/eSoul-cz/code-hunt-game/blob/master/include/functions.php), [texts.neon](https://github.com/eSoul-cz/code-hunt-game/blob/master/config/di/configs/texts.neon), [CompileTextCatalogCommand.php](https://github.com/eSoul-cz/code-hunt-game/blob/master/src/Console/Commands/CompileTextCatalogCommand.php) | Separate gettext/helper adapters and optional DI/console registration from standalone compilation. Remove game-key dependencies from command metadata/output. |
| Vite lifecycle | [textCatalog.ts](https://github.com/eSoul-cz/code-hunt-game/blob/master/vite/textCatalog.ts) | Generalize paths and the compiler invocation; retain coherent publication, HMR and SSR invalidation. Never copy the game's default command environment. |
| Macro transform | [textCatalogTransform.ts](https://github.com/eSoul-cz/code-hunt-game/blob/master/vite/textCatalogTransform.ts), [textCatalogTypes.ts](https://github.com/eSoul-cz/code-hunt-game/blob/master/vite/textCatalogTypes.ts) | Resolve package imports and explicitly configured application facades; stop recognizing hard-coded game aliases or emitting game-local imports. |
| Vue helpers | [productCopy.ts](https://github.com/eSoul-cz/code-hunt-game/blob/master/assets/js/productCopy.ts), [productCopyCompiled.ts](https://github.com/eSoul-cz/code-hunt-game/blob/master/assets/js/productCopyCompiled.ts), [productCopyLanguage.ts](https://github.com/eSoul-cz/code-hunt-game/blob/master/assets/js/productCopyLanguage.ts) | Inject generated data, language options and sanitizer; isolate mutable gettext state for each app/request. |
| HTML policy | [ProductCopyHtmlSanitizer.php](https://github.com/eSoul-cz/code-hunt-game/blob/master/src/Core/ProductCopyHtmlSanitizer.php), [productCopyHtml.ts](https://github.com/eSoul-cz/code-hunt-game/blob/master/assets/js/lib/productCopyHtml.ts) | Keep policy and concrete sanitizer implementation in the consumer; require an adapter for HTML-capable calls. |

## Intended interfaces — not implemented

### PHP module

Use constructor injection and typed configuration/result objects, retaining the catalog/loader/compiler cluster rather than inventing a general localization framework. Final method names and DTO fields must be checked against extracted callers before freezing them.

Configuration must explicitly cover:

- Source directory and project/source-reference root (no `ROOT` constant).
- PHP cache file, frontend artifact directory and gettext language directory/POT destination.
- Source locale, nonempty enabled locales containing the source locale, and gettext domain. No hard-coded Czech locale or game domain.
- Development versus production lookup policy matching the ADR; compilation remains an explicit build/deployment action, never a side effect of lookup.

Catalog lookup returns canonical source text and HTML/plural metadata. Translation and interpolation remain separate from source lookup. The compiler returns a typed result describing its generation and output locations; it must not expose app bootstrap or container internals. Preserve existing source-validation and translation-validation errors, while replacing game-specific diagnostic labels with package-owned diagnostics.

Standalone PHP loading/compilation must work without LSR, Vue, Node, an application container, Redis or application environment keys. Frontend generation must be selectable so a PHP-only consumer does not need npm installed.

### Optional LSR DI, console and helper adapters

Provide opt-in Nette registration mapping the same configuration into the standalone module. Do not auto-load framework classes or global functions through Composer on every install. LSR-specific dependencies remain optional; if an adapter subclasses a third-party class, document and verify its prerequisite before registering it.

Register a thin Symfony Console command as a DI service so `lsr/console` can discover it. Keep the existing `texts:cache:compile` command name as the extraction target, with package-owned literal description and success/error output that work before any catalog exists. Inject configured locales rather than reading `Lsr\Core\App` inside the compiler. The consumer still owns `bin/console`; verify discovery through its real entrypoint before advertising configuration examples.

Expose translated/HTML helper behavior through an injected adapter; do not install colliding global `text()` or `lang()` functions. App bootstrap may expose its own facade. Preserve native contextual/plural gettext behavior and interpolation order. Native gettext domain/locale setup and long-running worker reset remain explicit consumer responsibilities. No process-global mutable catalog/locale cache shared across tenants.

The arena and league app manifests currently allow `lsr/core ^0.3`, while the reference allows `^0.4` and `lsr/console ^0.2.0`. Do not infer compatibility from the reference or force either app to upgrade. Test each claimed optional integration range independently before declaring it.

### npm module and generated authoring types

Tentative subpaths are `/vite`, `/runtime`, `/compiled` and `/types`; none exist as exports today. Keep Node/Vite/compiler dependencies out of runtime and compiled browser import graphs. Add exports only when their built JavaScript and declarations exist in packed artifacts.

The Vite interface keeps `mode: 'runtime'` as default and explicit compiled opt-in. Configure project root, source/translation input roots, artifact locations, canonical facade resolution and a consumer-supplied compiler callback. Start with the existing `compile(root)` seam; if a richer typed context is needed, decide it alongside PHP output configuration. A generic compiler invocation must not inject app-only environment settings or require the game's `bin/console`.

Resolve package subpaths through Vite's resolver, not physical `node_modules` guesses. Canonicalize symlinked paths, imported aliases and configured facade files without rewriting unrelated lexical bindings. Compiled transformations must emit package imports, not `@/productCopy` or a game-local absolute path. Preserve plugin ordering before Vue and the ADR's diagnostics/evaluation-order rules; no silent runtime fallback in compiled mode.

Generated keys and translations belong to each consumer, not the npm package's static declarations. Retain PHP ownership of runtime TS, compiled TS and build JSON generation initially. Generate consumer-local authoring declarations/facade imports, visible to TypeScript before Vite runs (including `vue-tsc --noEmit`), while package `/types` holds only reusable types. Do not depend on a Vite-only virtual module without a standalone TypeScript resolution strategy. Generic generated imports must resolve via installed package exports, not the game's tsconfig aliases. Check generated `vue3-gettext` type imports against the eventual dependency/peer policy.

Runtime mode must retain typed dynamic source lookup and translated/plural/HTML helpers. Compiled mode must retain literal expansion without shipping the source lookup map. Each installed Vue app and SSR request receives its own mutable translation structures and active language; never mutate shared imported translation objects. Select the active app at invocation time and preserve the separate fixed source-language fallback outside an installed app.

### Application sanitization

PHP and Vue HTML helpers accept an application-provided sanitizer at installation/construction. Translate and interpolate first, then sanitize; retain explicit HTML-key eligibility. Missing sanitizer must reject HTML rendering rather than return raw HTML. Plain-text calls require no sanitizer. Browser and SSR adapters may use different implementations but must agree on policy and output. No package dependency on the game's Symfony sanitizer setup, DOMPurify policy, DOM bootstrap or Inertia props.

## PHP-to-npm artifact contract — proposed version 1

The current reference `catalog.build.json` contains `texts`, `htmlKeys` and `plurals` but no format version. Its Vite publication revision is internal, not a published interoperability contract. Do not describe the reference artifacts as already versioned.

Before independently versioning the packages, implement and document one producer/reader contract:

| Field in the proposed build manifest | Requirement |
| --- | --- |
| `formatVersion` | Integer `1` initially; npm rejects missing/unsupported versions with an actionable producer/reader compatibility error. It must not guess an old format. |
| `generation` | Deterministic digest identifying the complete produced artifact set for the configured inputs/options and format. Not a PHP/npm package version or timestamp. |
| `sourceLocale`, `locales`, `domain` | Explicit configuration identity, validated against the selected consumer configuration. |
| `artifacts` | Relative paths and SHA-256 digests for runtime TS, compiled TS and consumer authoring declarations if emitted separately. Paths stay within the configured output root. |
| `texts`, `htmlKeys`, `plurals` | Preserve the current typed snapshot semantics; HTML membership and plural child references must resolve to existing text keys. |

Specify canonical hashing bytes and serialization during the first implementation step and defend them with shared synthetic producer/reader fixtures. Compute artifact digests first and derive `generation` from a canonical manifest payload excluding `generation` itself; avoid circular hashes. Output code is trusted build output, not permission to execute an arbitrary downloaded manifest.

PHP validates the whole catalog/translation set before publishing generated outputs. Stage a complete generation and publish its manifest last. npm validates version, shape, referenced bytes and all required artifacts before replacing the currently visible generation. It must recheck input stability after the compiler callback and use one validated generation for client, SSR and custom environments. A failed or mixed generation blocks builds/reads; the last good object must not leak through the failure barrier. Preserve translator-owned PO contents and the ADR's input/output ownership; compiler-updated PO content must not create watcher loops.

Additive optional fields may stay within version 1 if older readers can safely ignore them. Changed required fields, generated export shapes, semantics or compatibility assumptions require a new format version. Record supported format versions in each package's release notes; package versions remain independent. Test supported pairs and reject unsupported pairs before transformation. No legacy format shim is part of the new package preparation.

## Ordered extraction work and exit criteria

1. **Approve extraction prerequisites.** Confirm code/fixture redistribution rights compatible with the approved MIT package license, final package names and registry/scope ownership. Use the existing GitHub origins above and pin the reference revision. Choose dependency support matrices; use the README dependency inventories as candidates, not tested promises. Exit: recorded approvals and supported target versions, with no app upgrades assumed.
2. **Implement standalone PHP and contract v1.** Extract the catalog cluster, remove constants/paths/bootstrap coupling, add synthetic catalog/translation fixtures and versioned artifact publication. Add only directly imported dependencies and real tooling/configuration. Verify core behavior without LSR or Node. Exit: isolated PHP compilation and negative cases produce/validate an actual artifact set, not empty output.
3. **Implement npm helpers and Vite reader/transform.** Consume contract v1, generalize paths/import resolution, inject language/sanitizer state and retain both modes. Build real ESM/declaration outputs; configure development tools and runtime/peer ranges from imports and tested versions. Exit: independent npm fixtures using PHP-produced artifacts pass with no game imports. npm helper consumption with prebuilt artifacts must not need PHP installed; regeneration may explicitly require the configured compiler callback.
4. **Consume through local dependencies in a disposable external consumer.** Create a consumer outside the reference repo; add a temporary Composer `path` repository with `options.symlink: true` pointing to `lsr-text-catalog` and an explicit development version constraint. Use pnpm `link:` for `lsr-text-catalog-js`; link only after build exports exist. Run only targeted dependency resolution, with approval for required network use. The consumer owns a small synthetic catalog, at least two locales, bootstrap and sanitizer adapters. Exit: source edits in both packages reach this consumer; no vendor/source copying or application migration is used to make it pass.
5. **Add optional LSR integration.** Exercise DI service/command discovery, native gettext helpers and CLI exit behavior in an independently bootstrapped consumer. Also retain a standalone consumer with no LSR packages. Exit: registration works without game catalog keys and failure propagates through the actual console command; optional integration does not become a core installation requirement.
6. **Verify packaged artifacts before any publication.** Build Composer archives and npm tarballs, remove local path/link dependencies from a fresh external consumer, install the archives, and run the release gate below. Ensure no relative sibling-documentation/source path is required at runtime. Exit: all advertised imports, declarations, commands and behavior work using only packed files and declared dependencies.
7. **Publish only with later authorization.** Use the existing separate repos/remotes and approved MIT licensing; remove npm `private` only after approvals and gates pass. Update runtime requirements, optional integration guidance, exports/files and release versions. Follow workspace Gitmoji release and Satis skills for Composer; publish npm separately. Install released versions in the external consumer and repeat the gate. No broad app dependency update, tags, push, Satis upload or npm publication is authorized by this plan.
8. **Migrate applications only in a later authorized task.** Select consumers and versions independently. Replace their implementations and all affected callers cleanly, retain app copy/configuration, then remove temporary links and verify published packages. Do not silently change LaserArenaControl, LaserLiga or code-hunt-game locks/constraints here.

## Future release gate: external consumer, installed archives

All rows are required before claiming extraction or publish-readiness. Record package versions, format version, dependency versions, commands and observed outcomes. A symlink-only pass or manifest validation is insufficient.

| Surface | Required observation |
| --- | --- |
| Independent installation | PHP-only catalog/compiler works without npm/LSR; npm helpers use prebuilt artifacts without PHP; Vite regeneration uses the explicit compiler adapter. Package archives contain all exported runtime/declaration files, no game aliases, vendor trees, secrets or editable application copy. |
| PHP compilation | Run real compilation in an external directory with nondefault paths/domain/locales; verify PHP cache, POT/PO merge preservation, MO and frontend artifacts. Invalid source/translation and partial-generation failure block consumption without corrupting translator-owned work. |
| Generated types | Run standalone TypeScript/Vue type checking before a Vite build; valid keys resolve, invalid keys/HTML eligibility fail, and two consumers with different catalogs have distinct key types. No dependency on the game's tsconfig or generated directory. |
| Runtime client and SSR | Mount a browser app and render SSR using runtime mode, dynamic keys, contextual translations, plurals and placeholders. Hydrated output agrees with server output. |
| Compiled client and SSR | Build and run both surfaces in explicit compiled mode; literal calls preserve semantics, forbidden dynamic/macro escapes produce diagnostics, and emitted client code does not retain the runtime source lookup table. |
| HMR/build watch | Edit source and editable PO inputs; observe unchanged callers and evaluated SSR refresh to the same generation. Ordinary Vue edits keep normal HMR. Exercise file/directory creation/deletion, failed compilation then repair, and watcher recovery per the ADR without stale or mixed output. |
| Translation safety | Exercise same-msgid/different-context entries, locale-specific multi-form plurals, placeholder preservation/interpolation and translation placeholder mismatch. Plain text remains escaped; marked HTML is sanitized after interpolation on PHP/browser/SSR; missing HTML sanitizer rejects the call. |
| App/request isolation | Mount two apps with different locales/catalogs/sanitizers, then interleave SSR requests and sequential long-running PHP requests. Changing one app/request cannot mutate another's translations or active locale; outside-app fallback stays fixed. |
| Format compatibility | Accept supported PHP/npm format pairs; reject unknown/missing versions, malformed key references, tampered/missing artifact bytes and mixed generations before serving/transformation. |
| Optional LSR | Compile the consumer container, list and execute the registered command with valid/invalid input; verify exit statuses and output without any game-owned command-copy key. Exercise each claimed framework constraint range separately. |

Use the reference verification assets as behavioral evidence rather than duplicating their inventory: [PHP catalog tests](https://github.com/eSoul-cz/code-hunt-game/blob/master/tests/Core/TextCatalogTest.php), [Vite lifecycle tests](https://github.com/eSoul-cz/code-hunt-game/blob/master/vite/textCatalog.test.ts), [transform tests](https://github.com/eSoul-cz/code-hunt-game/blob/master/vite/textCatalogTransform.test.ts), [runtime helper tests](https://github.com/eSoul-cz/code-hunt-game/blob/master/assets/js/productCopy.test.ts) and [compiled helper tests](https://github.com/eSoul-cz/code-hunt-game/blob/master/assets/js/productCopyCompiled.test.ts). Adapt only relevant observable contracts into package-owned synthetic fixtures after permission is settled. These reference suites have not been executed as part of skeleton preparation.

## Preparation validation (not a release gate)

From `lsr-text-catalog`, use `composer validate --strict --no-check-publish`. From `lsr-text-catalog-js`, use `npm pack --dry-run --ignore-scripts --json` to inspect included files without publishing or running lifecycle hooks. There are intentionally no build/test scripts to advertise before their implementations and tooling exist. These commands check skeleton metadata/packaging only; they prove no compilation, translation, Vue, SSR or HMR behavior.

Preparation checks run on 2026-09-08: Composer 2.10.3 / PHP 8.5.10 accepted the manifest with the command above; npm 11.17.0 completed the dry-run and listed only preparation files. The first check, before repository initialization, reported Composer's inferred root-version fallback; that was not a declared release. npm reported `.gitignore` fallback because a publication allowlist has intentionally not been selected yet. No dependencies, archives, runtime outputs or releases were created by these checks.
