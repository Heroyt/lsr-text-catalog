<?php

declare(strict_types=1);

namespace Lsr\TextCatalog;

use DirectoryIterator;
use Gettext\Generator\MoGenerator;
use Gettext\Generator\PoGenerator;
use Gettext\Loader\PoLoader;
use Gettext\Translation;
use Gettext\Translations;
use RuntimeException;
use stdClass;
use Throwable;
use UnexpectedValueException;

final readonly class TextCatalogCompiler
{
    public function __construct(
        private TextCatalogLoader $loader,
        private CatalogConfig $config,
    ) {
    }

    public function compile(): CompilationResult {
        $lockPath = $this->config->frontendDirectory === null
            ? $this->config->cacheFile . '.lock'
            : rtrim($this->config->frontendDirectory, '/\\') . '/catalog.build.lock';
        $this->ensureDirectory(dirname($lockPath));
        $lock = fopen($lockPath, 'c');
        if ($lock === false) {
            throw new RuntimeException('Unable to open text catalog compilation lock: ' . $lockPath);
        }
        try {
            if ( ! flock($lock, LOCK_EX)) {
                throw new RuntimeException('Unable to acquire text catalog compilation lock: ' . $lockPath);
            }
            return $this->compileLocked();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function compileLocked(): CompilationResult {
        $definition = $this->loader->load();
        $template = $this->compilePot($definition);
        $translations = [];
        foreach ($this->config->locales as $locale) {
            $translations[$locale] = $this->updateLocale($template, $locale, $locale === $this->config->sourceLocale);
            $this->validateLocale($translations[$locale], $locale);
        }

        // Nothing is published until every locale and every generated representation succeeds.
        $files = [];
        $poGenerator = new PoGenerator();
        $moGenerator = (new MoGenerator())->includeHeaders();
        $this->addOutput($files, $this->config->potFile, $poGenerator->generateString($template) . "\n");
        foreach ($translations as $locale => $localeTranslations) {
            $this->addOutput($files, $this->poPath($locale), $poGenerator->generateString($localeTranslations) . "\n");
            $this->addOutput($files, $this->moPath($locale), $moGenerator->generateString($localeTranslations));
        }
        $cache = [
            'texts' => $definition->texts,
            'htmlKeys' => $definition->htmlKeys,
            'plurals' => $definition->plurals,
        ];
        $this->addOutput(
            $files,
            $this->config->cacheFile,
            "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($cache, true) . ";\n",
        );

        $generation = null;
        $manifestFile = null;
        $textsDirectory = null;
        $generatedTexts = [];
        if ($this->config->frontendDirectory !== null) {
            $directory = rtrim($this->config->frontendDirectory, '/\\') . '/';
            $textsDirectory = $directory . 'texts';
            $frontend = $this->compileFrontend($definition, $translations);
            $groupArtifacts = [];
            $textPaths = [];
            foreach ($frontend['groups'] as $group => $modules) {
                $sourcePath = 'texts/' . $group . '.ts';
                $textPaths[$sourcePath] = $modules['source'];
                $translationArtifacts = [];
                foreach ($modules['translations'] as $locale => $contents) {
                    $translationPath = 'texts/' . $group . '.' . $locale . '.ts';
                    $textPaths[$translationPath] = $contents;
                    $translationArtifacts[$locale] = ['path' => $translationPath, 'sha256' => hash('sha256', $contents)];
                }
                $groupArtifacts[$group] = [
                    'source' => ['path' => $sourcePath, 'sha256' => hash('sha256', $modules['source'])],
                    'translations' => (object) $translationArtifacts,
                ];
            }
            // Consumers run on case-insensitive filesystems, so such paths would overwrite one another.
            $this->assertNoCaseCollisions(array_map(strval(...), array_keys($textPaths)));
            foreach ($textPaths as $path => $contents) {
                $this->addOutput($files, $directory . $path, $contents);
                $generatedTexts[] = $directory . $path;
            }
            $this->addOutput($files, $directory . 'catalog.ts', $frontend['runtime']);
            $this->addOutput($files, $directory . 'catalog.compiled.ts', $frontend['compiled']);
            $manifest = [
                'formatVersion' => 2,
                'sourceLocale' => $this->config->sourceLocale,
                'locales' => $this->config->locales,
                'domain' => $this->config->domain,
                'artifacts' => [
                    'runtime' => ['path' => 'catalog.ts', 'sha256' => hash('sha256', $frontend['runtime'])],
                    'compiled' => ['path' => 'catalog.compiled.ts', 'sha256' => hash('sha256', $frontend['compiled'])],
                    'groups' => (object) $groupArtifacts,
                ],
                'texts' => (object) $definition->texts,
                'htmlKeys' => (object) $definition->htmlKeys,
                'plurals' => (object) $definition->plurals,
            ];
            $generation = hash('sha256', $this->canonicalJson($manifest));
            $manifest['generation'] = $generation;
            $manifestFile = $directory . 'catalog.build.json';
            // Insertion order is publication order. The manifest is the final commit marker.
            $this->addOutput($files, $manifestFile, $this->json($manifest) . "\n");
        }
        $this->publish($files);
        $cleanupFailures = [];
        if ($textsDirectory !== null && is_dir($textsDirectory)) {
            // Only after the manifest was published: drop modules of groups/locales that no longer exist.
            // Best effort: the new generation is already live, so a failed deletion must not fail compilation.
            $cleanupFailures = $this->removeStaleTexts($textsDirectory, $generatedTexts);
        }

        return new CompilationResult($definition, $generation, $manifestFile, $cleanupFailures);
    }

    private function compilePot(TextCatalogDefinition $definition): Translations {
        $template = Translations::create($this->config->domain);
        $template->setDescription('Source copy extracted from the canonical NEON text catalog.');
        $pluralChildKeys = [];
        foreach ($definition->plurals as $context => $keys) {
            $oneKey = $keys['one'];
            $pluralKey = $keys['plural'];
            $translation = Translation::create($context, $definition->text($oneKey));
            $translation->setPlural($definition->text($pluralKey));
            $translation->getReferences()->add($this->relativePath($definition->sourceFiles[$oneKey]));
            $template->add($translation);
            $pluralChildKeys[$oneKey] = true;
            $pluralChildKeys[$pluralKey] = true;
        }
        foreach ($definition->texts as $key => $text) {
            if (isset($pluralChildKeys[$key])) {
                continue;
            }
            $translation = Translation::create($key, $text);
            $translation->getReferences()->add($this->relativePath($definition->sourceFiles[$key]));
            if ($definition->isHtml($key)) {
                $translation->getExtractedComments()->add('HTML; sanitize after translation and interpolation before raw rendering.');
            }
            $template->add($translation);
        }
        return $template;
    }

    /**
     * gettext's language table only knows plain `ll_CC` identifiers, while configured locales may carry a charset or
     * modifier (`sr@latin`, `de_DE.UTF-8`): plural rules come from the base language, the header keeps the full locale.
     */
    private function createLocaleTranslations(string $locale): Translations {
        $base = preg_replace('/[.@].*$/D', '', $locale) ?? $locale;
        $translations = Translations::create($this->config->domain, $base);
        $translations->getHeaders()->setLanguage($locale);
        return $translations;
    }

    private function updateLocale(Translations $template, string $locale, bool $sourceLocale): Translations {
        $path = $this->poPath($locale);
        $existing = is_file($path) ? (new PoLoader())->loadFile($path) : $this->createLocaleTranslations($locale);
        $updated = $this->createLocaleTranslations($locale);
        foreach ($existing->getHeaders() as $name => $value) {
            $updated->getHeaders()->set((string) $name, (string) $value);
        }
        $updated->setDomain($this->config->domain);
        // Keep the translator's Plural-Forms header; setLanguage() would replace it.
        $updated->getHeaders()->setLanguage($locale);
        /** @var array<string, Translation> $activeByContext */
        $activeByContext = [];
        /** @var array<string, Translation> $obsoleteByContext */
        $obsoleteByContext = [];
        foreach ($existing as $translation) {
            if ( ! $translation instanceof Translation) {
                throw new RuntimeException('Loaded text catalog PO contains an invalid translation.');
            }
            $context = $translation->getContext();
            if ($context === null) {
                continue;
            }
            if ($translation->isDisabled()) {
                $obsoleteByContext[$context] = $translation;
            } else {
                if (isset($activeByContext[$context])) {
                    throw new RuntimeException($locale . ': duplicate text catalog context "' . $context . '".');
                }
                $activeByContext[$context] = $translation;
            }
        }

        $activeContexts = [];
        foreach ($template as $source) {
            if ( ! $source instanceof Translation) {
                throw new RuntimeException('Generated text catalog template contains an invalid translation.');
            }
            $context = $source->getContext();
            if ($context === null) {
                throw new RuntimeException('Generated text catalog template entry has no semantic context.');
            }
            $activeContexts[$context] = true;
            $translation = clone $source;
            $previous = $activeByContext[$context] ?? $obsoleteByContext[$context] ?? null;
            $revived = ! isset($activeByContext[$context]) && isset($obsoleteByContext[$context]);
            if ($sourceLocale) {
                $translation->translate($source->getOriginal());
                if ($source->getPlural() !== null) {
                    $translation->translatePlural(...array_fill(0, $this->pluralCount($updated) - 1, $source->getPlural()));
                }
            } elseif ($previous !== null) {
                $translation->translate($previous->getTranslation() ?? '');
                $translation->translatePlural(...$previous->getPluralTranslations());
                foreach ($previous->getComments() as $comment) {
                    $translation->getComments()->add($comment);
                }
                foreach ($previous->getFlags() as $flag) {
                    $translation->getFlags()->add($flag);
                }
                $sourceChanged = $previous->getOriginal() !== $source->getOriginal()
                    || $previous->getPlural() !== $source->getPlural();
                if ($sourceChanged) {
                    $translation->setPreviousOriginal($previous->getOriginal());
                    $translation->setPreviousPlural($previous->getPlural());
                } else {
                    $translation->setPreviousOriginal($previous->getPreviousOriginal());
                    $translation->setPreviousPlural($previous->getPreviousPlural());
                }
                if ($revived || $sourceChanged) {
                    $translation->getFlags()->add('fuzzy');
                }
            }
            $updated->add($translation);
        }
        foreach ($existing as $translation) {
            if ( ! $translation instanceof Translation) {
                throw new RuntimeException('Loaded text catalog PO contains an invalid translation.');
            }
            $context = $translation->getContext();
            if ($context === null || isset($activeContexts[$context])) {
                continue;
            }
            $obsolete = clone $translation;
            $obsolete->disable();
            $updated->add($obsolete);
        }
        return $updated;
    }

    private function validateLocale(Translations $translations, string $locale): void {
        $pluralCount = $this->pluralCount($translations);
        foreach ($translations as $translation) {
            if ( ! $translation instanceof Translation) {
                throw new RuntimeException('Text catalog contains an invalid translation.');
            }
            if ($translation->isDisabled()) {
                continue;
            }
            $context = $translation->getContext() ?? $translation->getOriginal();
            if ($translation->getFlags()->has('fuzzy')) {
                throw new RuntimeException($locale . ': fuzzy text catalog translation "' . $context . '".');
            }
            if ( ! $translation->isTranslated()) {
                throw new RuntimeException($locale . ': missing text catalog translation "' . $context . '".');
            }
            $expectedPlaceholders = $this->placeholders($translation->getOriginal());
            $this->assertPlaceholderSet($expectedPlaceholders, $translation->getTranslation() ?? '', $locale, $context);
            if ($translation->getPlural() === null) {
                continue;
            }
            if ($expectedPlaceholders !== $this->placeholders($translation->getPlural())) {
                throw new RuntimeException($locale . ': source plural placeholders differ for "' . $context . '".');
            }
            foreach ($translation->getPluralTranslations($pluralCount - 1) as $pluralTranslation) {
                if ($pluralTranslation === '') {
                    throw new RuntimeException($locale . ': missing text catalog plural translation "' . $context . '".');
                }
                $this->assertPlaceholderSet($expectedPlaceholders, $pluralTranslation, $locale, $context);
            }
        }
    }

    /** @param list<string> $expected */
    private function assertPlaceholderSet(array $expected, string $translation, string $locale, string $context): void {
        if ($expected !== $this->placeholders($translation)) {
            throw new RuntimeException($locale . ': placeholder mismatch in text catalog translation "' . $context . '".');
        }
    }

    /**
     * @param array<non-empty-string, Translations> $translations
     * @return array{
     *     runtime: string,
     *     compiled: string,
     *     groups: array<string, array{source: string, translations: array<string, string>}>
     * } Runtime and compiled TypeScript bytes plus per-group source and per-locale translation modules.
     */
    private function compileFrontend(TextCatalogDefinition $definition, array $translations): array {
        /** @var array<string, array<string, string>> $groupTexts */
        $groupTexts = [];
        foreach ($definition->texts as $key => $text) {
            $key = (string) $key;
            $group = $this->group($key);
            // Keys are stored relative to their group; a root leaf (key equal to its group) becomes "".
            $groupTexts[$group][$key === $group ? '' : substr($key, strlen($group) + 1)] = $text;
        }
        ksort($groupTexts, SORT_STRING);

        // The source locale ships no translations: the gettext msgid already is the source text.
        /** @var array<string, array<string, array<string, array<string, string|list<string>>>>> $groupMessages */
        $groupMessages = [];
        foreach ($translations as $locale => $localeTranslations) {
            if ($locale === $this->config->sourceLocale) {
                continue;
            }
            foreach (array_keys($groupTexts) as $group) {
                $groupMessages[$group][$locale] = [];
            }
            foreach ($localeTranslations as $translation) {
                if ( ! $translation instanceof Translation) {
                    throw new RuntimeException('Text catalog contains an invalid translation.');
                }
                if ($translation->isDisabled()) {
                    continue;
                }
                $context = $translation->getContext();
                if ($context === null) {
                    throw new RuntimeException('Text catalog translation has no semantic context.');
                }
                $translated = $translation->getPlural() === null
                    ? ($translation->getTranslation() ?? '')
                    : array_merge([$translation->getTranslation() ?? ''], $translation->getPluralTranslations($this->pluralCount($localeTranslations) - 1));
                $groupMessages[$this->group($context)][$locale][$translation->getOriginal()][$context] = $translated;
            }
        }

        $groups = [];
        $runtimeGroups = [];
        $compiledGroups = [];
        foreach ($groupTexts as $group => $texts) {
            $source = "import type { CatalogSourceTexts } from 'lsr-text-catalog/types';\n\n"
                . 'export default ' . $this->typeScriptValue((object) $texts) . " satisfies CatalogSourceTexts;\n";
            $localeModules = [];
            $loaders = '';
            foreach (array_keys($groupMessages[$group] ?? []) as $locale) {
                $messages = $groupMessages[$group][$locale];
                ksort($messages, SORT_STRING);
                $localeModules[$locale] = "import type { CatalogMessages } from 'lsr-text-catalog/types';\n\n"
                    . 'export default ' . $this->typeScriptValue((object) $messages) . " satisfies CatalogMessages;\n";
                $loaders .= '            ' . $this->json($locale) . ": () => import('./texts/{$group}.{$locale}.js'),\n";
            }
            $translationsBlock = $loaders === ''
                ? "        translations: {},\n"
                : "        translations: {\n{$loaders}        },\n";
            $groupKey = $this->json($group);
            $runtimeGroups[] = "    {$groupKey}: {\n        source: () => import('./texts/{$group}.js'),\n{$translationsBlock}    },\n";
            $compiledGroups[] = "    {$groupKey}: {\n{$translationsBlock}    },\n";
            $groups[$group] = ['source' => $source, 'translations' => $localeModules];
        }

        $textKeyType = implode(' | ', array_map($this->json(...), array_keys($definition->texts))) ?: 'never';
        $htmlTextKeyType = implode(' | ', array_map($this->json(...), array_keys($definition->htmlKeys))) ?: 'never';
        $textGroupType = implode(' | ', array_map($this->json(...), array_keys($groupTexts))) ?: 'never';
        $htmlKeysJson = $this->json(array_keys($definition->htmlKeys));
        $sourceLocaleJson = $this->json($this->config->sourceLocale);
        $localesJson = $this->json($this->config->locales);
        $runtimeCatalogGroups = $runtimeGroups === [] ? '{}' : "{\n" . implode('', $runtimeGroups) . '}';
        $compiledCatalogGroups = $compiledGroups === [] ? '{}' : "{\n" . implode('', $compiledGroups) . '}';

        $compiled = <<<TYPESCRIPT
import type { CompiledCatalogGroups } from 'lsr-text-catalog/types';

export type TextKey = {$textKeyType};
export type HtmlTextKey = {$htmlTextKeyType};
export type TextGroup = {$textGroupType};
export const sourceLocale = {$sourceLocaleJson} as const;
export const locales = {$localesJson} as const;
export const catalogGroups = {$compiledCatalogGroups} satisfies CompiledCatalogGroups<TextGroup>;

// Authoring declaration: value uses must be expanded by the textCatalog Vite plugin.
export declare function text(key: TextKey): string;

TYPESCRIPT;

        $runtime = <<<TYPESCRIPT
import type { RuntimeCatalogGroups } from 'lsr-text-catalog/types';

export type TextKey = {$textKeyType};
export type HtmlTextKey = {$htmlTextKeyType};
export type TextGroup = {$textGroupType};
export const htmlTextKeys = {$htmlKeysJson} as readonly TextKey[];
export const sourceLocale = {$sourceLocaleJson} as const;
export const locales = {$localesJson} as const;
export const catalogGroups = {$runtimeCatalogGroups} satisfies RuntimeCatalogGroups<TextGroup>;

TYPESCRIPT;
        return ['runtime' => $runtime, 'compiled' => $compiled, 'groups' => $groups];
    }

    /** @return non-empty-string The root catalog key (first dotted segment) a key or gettext context belongs to. */
    private function group(string $key): string {
        $dot = strpos($key, '.');
        $group = $dot === false ? $key : substr($key, 0, $dot);
        if ($group === '') {
            throw new RuntimeException('Text catalog key has no group: "' . $key . '".');
        }
        return $group;
    }

    /** @param list<string> $paths */
    private function assertNoCaseCollisions(array $paths): void {
        $seen = [];
        foreach ($paths as $path) {
            $folded = strtolower($path);
            if (isset($seen[$folded])) {
                throw new RuntimeException('Text catalog artifact paths must not differ only by letter case: ' . $seen[$folded] . ', ' . $path);
            }
            $seen[$folded] = $path;
        }
    }

    /**
     * Deletes stale `texts/*.ts` files of previous generations. Never touches a file that is the new generation's
     * file under a different letter case (case-insensitive filesystems alias such names).
     *
     * @param list<string> $generated Absolute paths of the new generation's `texts/*.ts` files.
     * @return list<string> Paths that could not be removed.
     */
    private function removeStaleTexts(string $textsDirectory, array $generated): array {
        $generatedNames = [];
        $generatedByFolded = [];
        foreach ($generated as $path) {
            $name = basename($path);
            $generatedNames[$name] = true;
            $generatedByFolded[strtolower($name)] = $name;
        }
        $failed = [];
        try {
            $present = [];
            $candidates = [];
            foreach (new DirectoryIterator($textsDirectory) as $entry) {
                if ($entry->isDot()) {
                    continue;
                }
                $present[$entry->getFilename()] = true;
                if ( ! $entry->isLink() && $entry->isFile() && $entry->getExtension() === 'ts') {
                    $candidates[] = $entry->getFilename();
                }
            }
        } catch (UnexpectedValueException) {
            return [$textsDirectory];
        }
        foreach ($candidates as $name) {
            if (isset($generatedNames[$name])) {
                continue;
            }
            $folded = strtolower($name);
            // Same folded name as a new file and no separate exact-name entry: this entry is that file.
            if (isset($generatedByFolded[$folded]) && ! isset($present[$generatedByFolded[$folded]])) {
                continue;
            }
            $path = $textsDirectory . '/' . $name;
            if ( ! @unlink($path)) {
                $failed[] = $path;
            }
        }
        return $failed;
    }

    /** @return list<string> */
    private function placeholders(string $text): array {
        preg_match_all('/%\{\s*([A-Za-z_][A-Za-z0-9_.]*)\s*\}/', $text, $matches);
        $placeholders = array_values(array_unique($matches[1]));
        sort($placeholders, SORT_STRING);
        return $placeholders;
    }

    private function pluralCount(Translations $translations): int {
        $pluralForm = $translations->getHeaders()->getPluralForm();
        if ( ! is_array($pluralForm) || ! is_int($pluralForm[0]) || $pluralForm[0] < 1) {
            throw new RuntimeException('Text catalog locale must declare valid Plural-Forms.');
        }
        return $pluralForm[0];
    }

    private function poPath(string $locale): string {
        return rtrim($this->config->languageDirectory, '/\\') . '/' . $locale . '/LC_MESSAGES/' . $this->config->domain . '.po';
    }

    private function moPath(string $locale): string {
        return rtrim($this->config->languageDirectory, '/\\') . '/' . $locale . '/LC_MESSAGES/' . $this->config->domain . '.mo';
    }

    private function relativePath(string $path): string {
        $root = rtrim(str_replace('\\', '/', realpath($this->config->sourceRoot) ?: $this->config->sourceRoot), '/') . '/';
        $path = str_replace('\\', '/', realpath($path) ?: $path);
        return str_starts_with($path, $root) ? substr($path, strlen($root)) : $path;
    }

    private function json(mixed $value): string {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS);
    }

    private function canonicalJson(mixed $value): string {
        if ($value instanceof stdClass || (is_array($value) && ! array_is_list($value))) {
            $members = (array) $value;
            ksort($members, SORT_STRING);
            $encoded = [];
            foreach ($members as $key => $member) {
                $encoded[] = $this->json((string) $key) . ':' . $this->canonicalJson($member);
            }
            return '{' . implode(',', $encoded) . '}';
        }
        if (is_array($value)) {
            return '[' . implode(',', array_map($this->canonicalJson(...), $value)) . ']';
        }
        return $this->json($value);
    }

    private function typeScriptValue(mixed $value): string {
        if ($value instanceof stdClass || (is_array($value) && ! array_is_list($value))) {
            $encoded = [];
            foreach ((array) $value as $key => $member) {
                $property = $this->json((string) $key);
                // JSON's __proto__ property is data, but a JS object literal otherwise mutates its prototype.
                $encoded[] = ($key === '__proto__' ? '[' . $property . ']' : $property) . ':' . $this->typeScriptValue($member);
            }
            return '{' . implode(',', $encoded) . '}';
        }
        if (is_array($value)) {
            return '[' . implode(',', array_map($this->typeScriptValue(...), $value)) . ']';
        }
        return $this->json($value);
    }

    /** @param array<string, string> $files */
    private function addOutput(array &$files, string $path, string $contents): void {
        if (array_key_exists($path, $files)) {
            throw new RuntimeException('Text catalog output paths must be distinct: ' . $path);
        }
        $files[$path] = $contents;
    }

    /**
     * Atomically (per file) replaces the outputs, rolling everything back on failure. Files whose bytes already match
     * are left untouched. A file whose existing directory entry differs from the target name only by letter case
     * (case-insensitive filesystems keep the old entry name on `rename()`) is replaced by moving the old entry aside,
     * so the published file carries the exact new name.
     *
     * @param array<string, string> $files
     */
    private function publish(array $files): void {
        /** @var array<string, string> $staged */
        $staged = [];
        /** @var array<string, string> $backups */
        $backups = [];
        /** @var array<string, string> $caseRenames Target path => path of the differently-cased existing entry. */
        $caseRenames = [];
        /** @var array<string, array{aside: string, original: string}> $asides */
        $asides = [];
        /** @var array<string, list<string>> $listings */
        $listings = [];
        $published = [];
        $resolvedPaths = [];
        try {
            foreach ($files as $path => $contents) {
                $this->ensureDirectory(dirname($path));
                $resolved = realpath(dirname($path)) . '/' . basename($path);
                if (isset($resolvedPaths[$resolved])) {
                    throw new RuntimeException('Text catalog output paths must be distinct: ' . $path);
                }
                $resolvedPaths[$resolved] = true;
                if (is_link($path) || (file_exists($path) && ! is_file($path))) {
                    throw new RuntimeException('Text catalog output must be a regular file: ' . $path);
                }
                $exists = is_file($path);
                $existingName = $exists ? $this->existingEntryName($path, $listings) : basename($path);
                if ($exists && $existingName === basename($path) && $this->hasContents($path, $contents)) {
                    continue;
                }
                $temporary = $path . '.' . bin2hex(random_bytes(8)) . '.tmp';
                $staged[$path] = $temporary;
                if (file_put_contents($temporary, $contents, LOCK_EX) !== strlen($contents)) {
                    throw new RuntimeException('Unable to stage text catalog artifact: ' . $path);
                }
                if ($exists) {
                    if ($existingName === basename($path)) {
                        $backup = $path . '.' . bin2hex(random_bytes(8)) . '.bak';
                        $backups[$path] = $backup;
                        if ( ! copy($path, $backup)) {
                            throw new RuntimeException('Unable to back up text catalog artifact: ' . $path);
                        }
                    } else {
                        // The old entry itself becomes the backup when it is moved aside during publication.
                        $caseRenames[$path] = dirname($path) . '/' . $existingName;
                    }
                    $permissions = fileperms($path);
                    if ($permissions !== false && ! chmod($temporary, $permissions & 0777)) {
                        throw new RuntimeException('Unable to preserve text catalog artifact permissions: ' . $path);
                    }
                }
            }
            foreach ($staged as $path => $temporary) {
                if (isset($caseRenames[$path])) {
                    $aside = $path . '.' . bin2hex(random_bytes(8)) . '.old';
                    if ( ! rename($caseRenames[$path], $aside)) {
                        throw new RuntimeException('Unable to move aside text catalog artifact: ' . $caseRenames[$path]);
                    }
                    $asides[$path] = ['aside' => $aside, 'original' => $caseRenames[$path]];
                    // Registered before the final rename so that rollback also restores the moved-aside entry.
                    $published[] = $path;
                    if ( ! rename($temporary, $path)) {
                        throw new RuntimeException('Unable to replace text catalog artifact: ' . $path);
                    }
                    continue;
                }
                if ( ! rename($temporary, $path)) {
                    throw new RuntimeException('Unable to replace text catalog artifact: ' . $path);
                }
                $published[] = $path;
            }
        } catch (Throwable $exception) {
            $rollbackFailures = [];
            foreach (array_reverse($published) as $path) {
                if (isset($asides[$path])) {
                    $restored = ( ! file_exists($path) || unlink($path)) && rename($asides[$path]['aside'], $asides[$path]['original']);
                    // Either restored, or retained for manual recovery if the filesystem refuses rollback.
                    unset($asides[$path]);
                } else {
                    $restored = isset($backups[$path]) ? rename($backups[$path], $path) : unlink($path);
                    if ( ! $restored) {
                        // Retain the backup for manual recovery if the filesystem refuses rollback.
                        unset($backups[$path]);
                    }
                }
                if ( ! $restored) {
                    $rollbackFailures[] = $path;
                }
            }
            if ($rollbackFailures !== []) {
                throw new RuntimeException('Unable to restore text catalog artifacts: ' . implode(', ', $rollbackFailures), 0, $exception);
            }
            throw $exception;
        } finally {
            $leftovers = array_merge(
                array_values($staged),
                array_values($backups),
                array_map(static fn (array $moved): string => $moved['aside'], array_values($asides)),
            );
            foreach ($leftovers as $temporary) {
                if (is_file($temporary)) {
                    unlink($temporary);
                }
            }
        }
    }

    /**
     * @param array<string, list<string>> $listings Directory entry names, memoized per directory.
     * @return string The directory entry `$path` resolves to; differs from its basename only by letter case on case-insensitive filesystems.
     */
    private function existingEntryName(string $path, array &$listings): string {
        $directory = dirname($path);
        $listings[$directory] ??= array_values(array_filter(
            scandir($directory) ?: [],
            static fn (string $name): bool => $name !== '.' && $name !== '..',
        ));
        $name = basename($path);
        if (in_array($name, $listings[$directory], true)) {
            return $name;
        }
        foreach ($listings[$directory] as $entry) {
            if (strcasecmp($entry, $name) === 0) {
                return $entry;
            }
        }
        return $name;
    }

    private function hasContents(string $path, string $contents): bool {
        return filesize($path) === strlen($contents) && file_get_contents($path) === $contents;
    }

    private function ensureDirectory(string $directory): void {
        if (is_dir($directory)) {
            return;
        }
        if ( ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException('Unable to create text catalog artifact directory: ' . $directory);
        }
    }
}
