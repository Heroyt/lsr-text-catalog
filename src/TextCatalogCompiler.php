<?php

declare(strict_types=1);

namespace Lsr\TextCatalog;

use Gettext\Generator\MoGenerator;
use Gettext\Generator\PoGenerator;
use Gettext\Loader\PoLoader;
use Gettext\Translation;
use Gettext\Translations;
use RuntimeException;
use stdClass;
use Throwable;

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
        if ($this->config->frontendDirectory !== null) {
            $directory = rtrim($this->config->frontendDirectory, '/\\') . '/';
            [$runtime, $compiled] = $this->compileFrontend($definition, $translations);
            $this->addOutput($files, $directory . 'catalog.ts', $runtime);
            $this->addOutput($files, $directory . 'catalog.compiled.ts', $compiled);
            $manifest = [
                'formatVersion' => 1,
                'sourceLocale' => $this->config->sourceLocale,
                'locales' => $this->config->locales,
                'domain' => $this->config->domain,
                'artifacts' => [
                    'runtime' => ['path' => 'catalog.ts', 'sha256' => hash('sha256', $runtime)],
                    'compiled' => ['path' => 'catalog.compiled.ts', 'sha256' => hash('sha256', $compiled)],
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

        return new CompilationResult($definition, $generation, $manifestFile);
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

    private function updateLocale(Translations $template, string $locale, bool $sourceLocale): Translations {
        $path = $this->poPath($locale);
        $existing = is_file($path) ? (new PoLoader())->loadFile($path) : Translations::create($this->config->domain, $locale);
        $updated = Translations::create($this->config->domain, $locale);
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
     * @return array{string, string} Runtime and compiled TypeScript bytes.
     */
    private function compileFrontend(TextCatalogDefinition $definition, array $translations): array {
        $frontendTranslations = [];
        foreach ($translations as $locale => $localeTranslations) {
            $messages = [];
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
                $messages[$translation->getOriginal()][$context] = $translated;
            }
            ksort($messages, SORT_STRING);
            $frontendTranslations[$locale] = (object) $messages;
        }
        $textKeyType = implode(' | ', array_map($this->json(...), array_keys($definition->texts))) ?: 'never';
        $htmlTextKeyType = implode(' | ', array_map($this->json(...), array_keys($definition->htmlKeys))) ?: 'never';
        $translationsJson = $this->typeScriptValue((object) $frontendTranslations);
        $sourceLocaleJson = $this->json($this->config->sourceLocale);
        $localesJson = $this->json($this->config->locales);
        $compiled = <<<TYPESCRIPT
import type { Translations } from '@lsr/text-catalog/types';

export type TextKey = {$textKeyType};
export type HtmlTextKey = {$htmlTextKeyType};
export const sourceLocale = {$sourceLocaleJson} as const;
export const locales = {$localesJson} as const;
export const catalogTranslations = {$translationsJson} satisfies Translations;

// Authoring declaration: value uses must be expanded by the textCatalog Vite plugin.
export declare function text(key: TextKey): string;

TYPESCRIPT;

        $catalogJson = $this->typeScriptValue((object) $definition->texts);
        $htmlKeysJson = $this->json(array_keys($definition->htmlKeys));
        $runtime = <<<TYPESCRIPT
import type { Translations } from '@lsr/text-catalog/types';

export const catalog = {$catalogJson} as const;
export type TextKey = keyof typeof catalog;
export type HtmlTextKey = {$htmlTextKeyType};
export const htmlTextKeys = {$htmlKeysJson} as readonly TextKey[];
export const sourceLocale = {$sourceLocaleJson} as const;
export const locales = {$localesJson} as const;
export const catalogTranslations = {$translationsJson} satisfies Translations;

export function text(key: TextKey): string {
    if (!Object.prototype.hasOwnProperty.call(catalog, key)) {
        throw new Error(`Unknown text catalog key "\${key}".`);
    }
    return catalog[key];
}

TYPESCRIPT;
        return [$runtime, $compiled];
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

    /** @param array<string, string> $files */
    private function publish(array $files): void {
        /** @var array<string, string> $staged */
        $staged = [];
        /** @var array<string, string> $backups */
        $backups = [];
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
                $temporary = $path . '.' . bin2hex(random_bytes(8)) . '.tmp';
                $staged[$path] = $temporary;
                if (file_put_contents($temporary, $contents, LOCK_EX) !== strlen($contents)) {
                    throw new RuntimeException('Unable to stage text catalog artifact: ' . $path);
                }
                if (is_file($path)) {
                    $backup = $path . '.' . bin2hex(random_bytes(8)) . '.bak';
                    $backups[$path] = $backup;
                    if ( ! copy($path, $backup)) {
                        throw new RuntimeException('Unable to back up text catalog artifact: ' . $path);
                    }
                    $permissions = fileperms($path);
                    if ($permissions !== false && ! chmod($temporary, $permissions & 0777)) {
                        throw new RuntimeException('Unable to preserve text catalog artifact permissions: ' . $path);
                    }
                }
            }
            foreach ($staged as $path => $temporary) {
                if ( ! rename($temporary, $path)) {
                    throw new RuntimeException('Unable to replace text catalog artifact: ' . $path);
                }
                $published[] = $path;
            }
        } catch (Throwable $exception) {
            $rollbackFailures = [];
            foreach (array_reverse($published) as $path) {
                $restored = isset($backups[$path]) ? rename($backups[$path], $path) : unlink($path);
                if ( ! $restored) {
                    $rollbackFailures[] = $path;
                    // Retain the backup for manual recovery if the filesystem refuses rollback.
                    unset($backups[$path]);
                }
            }
            if ($rollbackFailures !== []) {
                throw new RuntimeException('Unable to restore text catalog artifacts: ' . implode(', ', $rollbackFailures), 0, $exception);
            }
            throw $exception;
        } finally {
            foreach (array_merge(array_values($staged), array_values($backups)) as $temporary) {
                if (is_file($temporary)) {
                    unlink($temporary);
                }
            }
        }
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
