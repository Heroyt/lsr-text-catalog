<?php

declare(strict_types=1);

namespace Tests\Core;

use FilesystemIterator;
use Gettext\Generator\PoGenerator;
use Gettext\Loader\MoLoader;
use Gettext\Loader\PoLoader;
use Gettext\Translation;
use Gettext\Translations;
use InvalidArgumentException;
use JsonException;
use Lsr\TextCatalog\CatalogConfig;
use Lsr\TextCatalog\TextCatalog;
use Lsr\TextCatalog\TextCatalogCompiler;
use Lsr\TextCatalog\TextCatalogLoader;
use OutOfBoundsException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use stdClass;

final class TextCatalogTest extends TestCase
{
    private string $temporaryDirectory;

    protected function setUp(): void {
        $this->temporaryDirectory = sys_get_temp_dir() . '/lsr-text-catalog-' . bin2hex(random_bytes(8));
        mkdir($this->temporaryDirectory . '/texts', 0777, true);
    }

    protected function tearDown(): void {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->temporaryDirectory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $file) {
            $file->isDir() && ! $file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->temporaryDirectory);
    }

    public function test_loader_normalizes_only_allowlisted_entities_and_preserves_lookup_placeholders(): void {
        $this->writeSource('shelf.neon', <<<'NEON'
shelf:
    title: 'Reading&nbsp;room&#160;open&#xA0;today&#xa0;only &copy;'
    greeting: 'Welcome %{reader.name}.'
    rich:
        html: 'Read the <strong>instructions</strong>.'
    books:
        one: '%{count} book'
        plural: '%{count} books'
NEON);
        $definition = $this->loader()->load();
        self::assertSame("Reading\u{00A0}room\u{00A0}open\u{00A0}today\u{00A0}only &copy;", $definition->text('shelf.title'));
        self::assertSame('Welcome %{reader.name}.', $definition->text('shelf.greeting'));
        self::assertTrue($definition->isHtml('shelf.rich'));
        self::assertFalse($definition->isHtml('shelf.title'));
        self::assertSame(['one' => 'shelf.books.one', 'plural' => 'shelf.books.plural'], $definition->plurals['shelf.books']);
        self::assertFalse($definition->has('shelf.books'));
    }

    public function test_recursive_namespaces_and_file_roots_keep_editorial_keys_distinct(): void {
        $this->writeSource('shelf.neon', "shelf:\n    title: Public shelf\n");
        $this->writeSource('staff/shelf.neon', "shelf:\n    title: Staff shelf\n");
        $this->writeSource('staff/archive/shelf.neon', "shelf:\n    title: Archived shelf\n");
        self::assertSame([
            'shelf.title' => 'Public shelf',
            'staff.archive.shelf.title' => 'Archived shelf',
            'staff.shelf.title' => 'Staff shelf',
        ], $this->loader()->load()->texts);
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidSources(): iterable {
        yield 'wrong root' => ['shelf.neon', "other:\n    title: Read\n"];
        yield 'extra root' => ['shelf.neon', "shelf:\n    title: Read\nother:\n    title: Write\n"];
        yield 'dotted root' => ['shelf.neon', "shelf.title: Read\n"];
        yield 'dotted child' => ['shelf.neon', "shelf:\n    card.title: Read\n"];
        yield 'dotted directory' => ['staff.old/shelf.neon', "shelf:\n    title: Read\n"];
        yield 'hyphenated filename' => ['shelf-list.neon', "shelfList:\n    title: Read\n"];
        yield 'directory repeated in root' => ['staff/shelf.neon', "staff:\n    shelf:\n        title: Read\n"];
        yield 'numeric value' => ['shelf.neon', "shelf:\n    title: 42\n"];
        yield 'empty value' => ['shelf.neon', "shelf:\n    title: ''\n"];
        yield 'list value' => ['shelf.neon', "shelf:\n    title: [Read, Write]\n"];
        yield 'incomplete plural' => ['shelf.neon', "shelf:\n    books:\n        one: Book\n"];
        yield 'mixed html section' => ['shelf.neon', "shelf:\n    card:\n        html: '<strong>Read</strong>'\n        title: Read\n"];
        yield 'plural placeholder mismatch' => ['shelf.neon', "shelf:\n    books:\n        one: '%{count} book'\n        plural: '%{total} books'\n"];
        yield 'numeric html' => ['shelf.neon', "shelf:\n    card:\n        html: 1\n"];
    }

    #[DataProvider('invalidSources')]
    public function test_loader_rejects_invalid_source_shapes(string $path, string $contents): void {
        $this->writeSource($path, $contents);
        $this->expectException(InvalidArgumentException::class);
        $this->loader()->load();
    }

    public function test_flat_and_recursive_files_cannot_define_the_same_context(): void {
        $this->writeSource('staff.neon', "staff:\n    shelf:\n        title: One\n");
        $this->writeSource('staff/shelf.neon', "shelf:\n    title: Two\n");
        $this->expectException(InvalidArgumentException::class);
        $this->loader()->load();
    }

    /** @return iterable<string, array{string}> */
    public static function unsafeHtml(): iterable {
        yield 'script' => ['<script>alert(1)</script>'];
        yield 'event attribute' => ['<strong onclick="alert(1)">Read</strong>'];
        yield 'javascript target' => ['<a href="javascript:alert(1)">Read</a>'];
        yield 'protocol relative target' => ['<a href="//unsafe.example/book">Read</a>'];
        yield 'encoded scheme' => ['<a href="jav&#x61;script:alert(1)">Read</a>'];
        yield 'insecure scheme' => ['<a href="http://example.test/book">Read</a>'];
        yield 'root marker does not bypass validation' => ['<a data-catalog-root href="https://example.test">Read</a>'];
        yield 'malformed closing tag' => ['<strong>Read</em>'];
    }

    #[DataProvider('unsafeHtml')]
    public function test_loader_rejects_unsafe_or_malformed_html(string $html): void {
        $this->writeSource('shelf.neon', "shelf:\n    rich:\n        html: '" . $html . "'\n");
        $this->expectException(InvalidArgumentException::class);
        $this->loader()->load();
    }

    public function test_html_validation_accepts_explicit_safe_links_and_placeholder_targets(): void {
        $html = '<a href="https://example.test/book" rel="noopener" target="_blank">Read</a>'
            . '<a href="/books">Browse</a><a href="#index">Index</a><a href="?page=2">Next</a>'
            . '<a href="mailto:reader@example.test">Mail</a><a href="tel:+1234">Call</a>'
            . '<a href="%{url}"><em>Open</em></a>';
        $this->writeSource('shelf.neon', "shelf:\n    rich:\n        html: '" . $html . "'\n");
        self::assertSame($html, $this->loader()->load()->text('shelf.rich'));
    }

    public function test_empty_source_tree_is_an_error_not_an_empty_catalog(): void {
        $this->expectException(RuntimeException::class);
        $this->loader()->load();
    }

    public function test_lookup_memoizes_source_without_compilation_and_unknown_keys_throw(): void {
        $this->writeSource('shelf.neon', "shelf:\n    title: First edition\n");
        $catalog = new TextCatalog($this->loader(), $this->temporaryDirectory . '/missing/cache.php', false);
        self::assertSame('First edition', $catalog->text('shelf.title'));
        $this->writeSource('shelf.neon', "shelf:\n    title: Second edition\n");
        self::assertSame(['shelf.title' => 'First edition'], $catalog->all());
        self::assertFileDoesNotExist($this->temporaryDirectory . '/missing/cache.php');
        self::assertFalse($catalog->has('shelf.missing'));
        $this->expectException(OutOfBoundsException::class);
        $catalog->text('shelf.missing');
    }

    public function test_compiled_lookup_is_explicit_and_missing_cache_falls_back_to_sources(): void {
        $this->writeSource('shelf.neon', "shelf:\n    title: First edition\n");
        $config = $this->config();
        $this->compiler($config)->compile();
        $this->writeSource('shelf.neon', "shelf:\n    title: Second edition\n");
        self::assertSame('First edition', (new TextCatalog(new TextCatalogLoader('/unused'), $config->cacheFile, true))->text('shelf.title'));
        self::assertSame('Second edition', (new TextCatalog($this->loader(), $config->cacheFile, false))->text('shelf.title'));
        self::assertSame('Second edition', (new TextCatalog($this->loader(), $config->cacheFile . '.missing', true))->text('shelf.title'));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function corruptCaches(): iterable {
        yield 'missing structure' => [['texts' => []]];
        yield 'non-string text' => [['texts' => ['shelf.title' => 42], 'htmlKeys' => [], 'plurals' => []]];
        yield 'missing HTML reference' => [['texts' => ['shelf.title' => 'Read'], 'htmlKeys' => ['shelf.missing' => true], 'plurals' => []]];
        yield 'invalid HTML membership' => [['texts' => ['shelf.title' => 'Read'], 'htmlKeys' => ['shelf.title' => false], 'plurals' => []]];
        yield 'plural references another context' => [[
            'texts' => ['shelf.books.one' => 'Book', 'shelf.books.plural' => 'Books'],
            'htmlKeys' => [],
            'plurals' => ['shelf.readers' => ['one' => 'shelf.books.one', 'plural' => 'shelf.books.plural']],
        ]];
    }

    /** @param array<string, mixed> $cache */
    #[DataProvider('corruptCaches')]
    public function test_lookup_rejects_corrupt_compiled_cache_without_silent_source_fallback(array $cache): void {
        $this->writeSource('shelf.neon', "shelf:\n    title: Read\n");
        $cacheFile = $this->temporaryDirectory . '/cache.php';
        file_put_contents($cacheFile, "<?php\nreturn " . var_export($cache, true) . ";\n");
        $catalog = new TextCatalog($this->loader(), $cacheFile, true);
        $this->expectException(RuntimeException::class);
        $catalog->text('shelf.title');
    }

    public function test_compiler_emits_contextual_gettext_with_locale_plural_count_and_portable_references(): void {
        $this->writeSource('staff/archive/shelf.neon', <<<'NEON'
shelf:
    greeting: 'Vítejte %{name}.'
    rich:
        html: 'Přečtěte si <strong>pokyny</strong>.'
    books:
        one: '%{count} kniha'
        plural: '%{count} knihy'
NEON);
        $config = $this->config(['cs_CZ'], 'cs_CZ');
        $result = $this->compiler($config)->compile();
        $template = (new PoLoader())->loadFile($config->potFile);
        $greeting = $template->find('staff.archive.shelf.greeting', 'Vítejte %{name}.');
        self::assertNotNull($greeting);
        self::assertSame(['texts/staff/archive/shelf.neon' => []], $greeting->getReferences()->toArray());
        $mo = (new MoLoader())->loadFile($this->localePath('cs_CZ', 'mo'));
        self::assertSame(3, $mo->getHeaders()->getPluralForm()[0] ?? null);
        self::assertSame('Vítejte %{name}.', $mo->find('staff.archive.shelf.greeting', 'Vítejte %{name}.')?->getTranslation());
        $plural = $mo->find('staff.archive.shelf.books', '%{count} kniha');
        self::assertNotNull($plural);
        self::assertSame('%{count} knihy', $plural->getPlural());
        self::assertSame(['%{count} knihy', '%{count} knihy'], $plural->getPluralTranslations());
        self::assertNull($mo->find('staff.archive.shelf.books.one', '%{count} kniha'));
        $lookup = new TextCatalog(new TextCatalogLoader('/unused'), $config->cacheFile, true);
        self::assertTrue($lookup->isHtml('staff.archive.shelf.rich'));
        self::assertSame($result->definition->texts, $lookup->all());
    }

    public function test_manifest_hashes_exact_artifacts_and_uses_canonical_unicode_json_with_object_records(): void {
        $source = "Čtení/path\u{2028}next\u{2029}last";
        $this->writeSource('shelf.neon', "shelf:\n    title: '" . $source . "'\n");
        $config = $this->config();
        $result = $this->compiler($config)->compile();
        self::assertNotNull($result->manifestFile);
        $manifest = json_decode((string) file_get_contents($result->manifestFile), flags: JSON_THROW_ON_ERROR);
        self::assertInstanceOf(stdClass::class, $manifest);
        self::assertInstanceOf(stdClass::class, $manifest->texts);
        self::assertInstanceOf(stdClass::class, $manifest->htmlKeys);
        self::assertInstanceOf(stdClass::class, $manifest->plurals);
        self::assertSame(['shelf.title' => $source], (array) $manifest->texts);
        self::assertSame([], (array) $manifest->htmlKeys);
        self::assertSame([], (array) $manifest->plurals);
        self::assertSame(1, $manifest->formatVersion);
        self::assertSame('library', $manifest->domain);
        self::assertSame('en_US', $manifest->sourceLocale);
        self::assertSame(['en_US'], $manifest->locales);
        self::assertSame('catalog.ts', $manifest->artifacts->runtime->path);
        self::assertSame('catalog.compiled.ts', $manifest->artifacts->compiled->path);
        $runtimeHash = hash_file('sha256', $config->frontendDirectory . '/catalog.ts');
        $compiledHash = hash_file('sha256', $config->frontendDirectory . '/catalog.compiled.ts');
        self::assertSame($runtimeHash, $manifest->artifacts->runtime->sha256);
        self::assertSame($compiledHash, $manifest->artifacts->compiled->sha256);
        $canonical = '{"artifacts":{"compiled":{"path":"catalog.compiled.ts","sha256":"' . $compiledHash
            . '"},"runtime":{"path":"catalog.ts","sha256":"' . $runtimeHash
            . '"}},"domain":"library","formatVersion":1,"htmlKeys":{},"locales":["en_US"],"plurals":{},'
            . '"sourceLocale":"en_US","texts":{"shelf.title":"' . $source . '"}}';
        self::assertSame(hash('sha256', $canonical), $manifest->generation);
        self::assertSame($manifest->generation, $result->generation);
        self::assertSame($result->generation, $this->compiler($config)->compile()->generation);
    }

    public function test_php_only_compilation_supports_custom_pot_path_without_frontend_artifacts(): void {
        $this->writeSource('shelf.neon', "shelf:\n    title: Read\n");
        $pot = $this->temporaryDirectory . '/editorial/source.pot';
        $config = $this->config(frontend: false, potFile: $pot);
        $result = $this->compiler($config)->compile();
        self::assertNull($result->generation);
        self::assertNull($result->manifestFile);
        self::assertFileDoesNotExist($this->temporaryDirectory . '/frontend/catalog.ts');
        self::assertFileDoesNotExist($this->temporaryDirectory . '/languages/library.pot');
        self::assertNotNull((new PoLoader())->loadFile($pot)->find('shelf.title', 'Read'));
        self::assertSame('Read', (new TextCatalog(new TextCatalogLoader('/unused'), $config->cacheFile, true))->text('shelf.title'));
    }

    public function test_source_locale_refreshes_without_fuzzy_and_preserves_translator_headers(): void {
        $this->writeSource('shelf.neon', "shelf:\n    title: First edition\n");
        $compiler = $this->compiler($this->config());
        $compiler->compile();
        $po = (new PoLoader())->loadFile($this->localePath('en_US', 'po'));
        $po->getHeaders()->set('Last-Translator', 'Reader <reader@example.test>');
        $po->getHeaders()->set('X-Editorial-Review', 'approved');
        (new PoGenerator())->generateFile($po, $this->localePath('en_US', 'po'));
        $this->writeSource('shelf.neon', "shelf:\n    title: Second edition\n");
        $compiler->compile();
        $updated = (new PoLoader())->loadFile($this->localePath('en_US', 'po'));
        self::assertSame('Reader <reader@example.test>', $updated->getHeaders()->get('Last-Translator'));
        self::assertSame('approved', $updated->getHeaders()->get('X-Editorial-Review'));
        self::assertNull($updated->find('shelf.title', 'First edition'));
        $translation = $updated->find('shelf.title', 'Second edition');
        self::assertNotNull($translation);
        self::assertSame('Second edition', $translation->getTranslation());
        self::assertFalse($translation->getFlags()->has('fuzzy'));
    }

    public function test_non_source_translator_values_comments_and_plural_rules_survive_successful_merge(): void {
        $this->writeSource('shelf.neon', "shelf:\n    books:\n        one: '%{count} book'\n        plural: '%{count} books'\n");
        $po = Translations::create('library', 'cs_CZ');
        $po->getHeaders()->set('Last-Translator', 'Reader');
        $po->getHeaders()->setPluralForm(4, '(n == 1) ? 0 : (n == 2) ? 1 : (n == 3) ? 2 : 3');
        $entry = Translation::create('shelf.books', '%{count} book');
        $entry->setPlural('%{count} books')->translate('%{count} kniha')->translatePlural('%{count} knihy', '%{count} knih', '%{count} svazků');
        $entry->getComments()->add('Reviewed by a librarian.');
        $po->add($entry);
        $this->writePo('cs_CZ', $po);
        $this->compiler($this->config(['en_US', 'cs_CZ']))->compile();
        $merged = (new PoLoader())->loadFile($this->localePath('cs_CZ', 'po'));
        $translation = $merged->find('shelf.books', '%{count} book');
        self::assertNotNull($translation);
        self::assertSame('%{count} kniha', $translation->getTranslation());
        self::assertSame(['%{count} knihy', '%{count} knih', '%{count} svazků'], $translation->getPluralTranslations());
        self::assertSame(['Reviewed by a librarian.'], $translation->getComments()->toArray());
        self::assertSame('Reader', $merged->getHeaders()->get('Last-Translator'));
        self::assertSame(4, $merged->getHeaders()->getPluralForm()[0] ?? null);
        $compiled = (new MoLoader())->loadFile($this->localePath('cs_CZ', 'mo'));
        self::assertSame(['%{count} knihy', '%{count} knih', '%{count} svazků'], $compiled->find('shelf.books', '%{count} book')?->getPluralTranslations());
    }

    public function test_failed_validation_preserves_all_existing_po_and_generated_outputs_then_recovers(): void {
        $this->writeSource('shelf.neon', "shelf:\n    greeting: 'Welcome %{name}.'\n");
        $this->compiler($this->config())->compile();
        $po = Translations::create('library', 'cs_CZ');
        $entry = Translation::create('shelf.greeting', 'Welcome %{name}.');
        $entry->translate('Vítejte %{wrong}.');
        $po->add($entry);
        $this->writePo('cs_CZ', $po);
        $before = $this->artifactBytes();
        $compiler = $this->compiler($this->config(['en_US', 'cs_CZ']));
        try {
            $compiler->compile();
            self::fail('An invalid translated placeholder must prevent publication.');
        } catch (RuntimeException) {
            self::assertSame($before, $this->artifactBytes());
        }
        $entry->translate('Vítejte %{name}.');
        $this->writePo('cs_CZ', $po);
        $compiler->compile();
        $compiled = (new MoLoader())->loadFile($this->localePath('cs_CZ', 'mo'));
        self::assertSame('Vítejte %{name}.', $compiled->find('shelf.greeting', 'Welcome %{name}.')?->getTranslation());
    }

    public function test_frontend_encoding_failure_preserves_gettext_outputs_and_translator_po(): void {
        $this->writeSource('shelf.neon', "shelf:\n    title: Read\n");
        $this->compiler($this->config())->compile();
        $po = Translations::create('library', 'cs_CZ');
        $entry = Translation::create('shelf.title', 'Read');
        $entry->translate("Read \xFF");
        $po->add($entry);
        $this->writePo('cs_CZ', $po);
        $before = $this->artifactBytes();
        try {
            $this->compiler($this->config(['en_US', 'cs_CZ']))->compile();
            self::fail('Invalid UTF-8 must fail JSON generation before any output is replaced.');
        } catch (JsonException) {
            self::assertSame($before, $this->artifactBytes());
        }
    }

    public function test_changed_non_source_msgid_requires_review_without_rewriting_translator_po(): void {
        $this->writeSource('shelf.neon', "shelf:\n    title: Read now\n");
        $po = Translations::create('library', 'cs_CZ');
        $entry = Translation::create('shelf.title', 'Read now');
        $entry->translate('Čtěte nyní');
        $po->add($entry);
        $this->writePo('cs_CZ', $po);
        $compiler = $this->compiler($this->config(['en_US', 'cs_CZ']));
        $compiler->compile();
        $before = $this->artifactBytes();
        $this->writeSource('shelf.neon', "shelf:\n    title: Read later\n");
        try {
            $compiler->compile();
            self::fail('Changed source copy must require non-source translator review.');
        } catch (RuntimeException) {
            self::assertSame($before, $this->artifactBytes());
        }
        $cached = new TextCatalog(new TextCatalogLoader('/unused'), $this->config()->cacheFile, true);
        self::assertSame('Read now', $cached->text('shelf.title'));
    }

    public function test_removed_translations_become_obsolete_and_revival_requires_review_without_mutation(): void {
        $this->writeSource('shelf.neon', "shelf:\n    title: Read\n    subtitle: Learn\n");
        $po = Translations::create('library', 'cs_CZ');
        $title = Translation::create('shelf.title', 'Read');
        $title->translate('Čtěte');
        $subtitle = Translation::create('shelf.subtitle', 'Learn');
        $subtitle->translate('Učte se');
        $po->add($title)->add($subtitle);
        $this->writePo('cs_CZ', $po);
        $compiler = $this->compiler($this->config(['en_US', 'cs_CZ']));
        $compiler->compile();
        $this->writeSource('shelf.neon', "shelf:\n    title: Read\n");
        $compiler->compile();
        $obsolete = (new PoLoader())->loadFile($this->localePath('cs_CZ', 'po'))->find('shelf.subtitle', 'Learn');
        self::assertNotNull($obsolete);
        self::assertTrue($obsolete->isDisabled());
        self::assertSame('Učte se', $obsolete->getTranslation());
        self::assertNull((new MoLoader())->loadFile($this->localePath('cs_CZ', 'mo'))->find('shelf.subtitle', 'Learn'));
        $before = $this->artifactBytes();
        $this->writeSource('shelf.neon', "shelf:\n    title: Read\n    subtitle: Learn\n");
        try {
            $compiler->compile();
            self::fail('Revived translator copy must be reviewed before publication.');
        } catch (RuntimeException) {
            self::assertSame($before, $this->artifactBytes());
        }
    }

    public function test_incomplete_initial_locale_does_not_publish_even_pot_or_source_po(): void {
        $this->writeSource('shelf.neon', "shelf:\n    title: Read\n");
        try {
            $this->compiler($this->config(['en_US', 'cs_CZ']))->compile();
            self::fail('Missing translations must prevent initial publication.');
        } catch (RuntimeException) {
            self::assertSame([], $this->artifactBytes());
        }
    }

    public function test_missing_locale_specific_plural_form_blocks_publication(): void {
        $this->writeSource('shelf.neon', "shelf:\n    books:\n        one: '%{count} book'\n        plural: '%{count} books'\n");
        $po = Translations::create('library', 'cs_CZ');
        $entry = Translation::create('shelf.books', '%{count} book');
        $entry->setPlural('%{count} books')->translate('%{count} kniha')->translatePlural('%{count} knihy');
        $po->add($entry);
        $this->writePo('cs_CZ', $po);
        $before = $this->artifactBytes();
        try {
            $this->compiler($this->config(['en_US', 'cs_CZ']))->compile();
            self::fail('Every locale-specific plural form must be translated.');
        } catch (RuntimeException) {
            self::assertSame($before, $this->artifactBytes());
        }
    }

    public function test_staging_failure_does_not_delete_or_replace_preexisting_outputs(): void {
        $this->writeSource('shelf.neon', "shelf:\n    title: First edition\n");
        $config = $this->config();
        $compiler = $this->compiler($config);
        $compiler->compile();
        $cache = (string) file_get_contents($config->cacheFile);
        unlink($config->cacheFile);
        mkdir($config->cacheFile);
        $before = $this->artifactBytes();
        $this->writeSource('shelf.neon', "shelf:\n    title: Second edition\n");
        try {
            $compiler->compile();
            self::fail('A directory at an output path must block publication.');
        } catch (RuntimeException) {
            self::assertSame($before, $this->artifactBytes());
            self::assertDirectoryExists($config->cacheFile);
        }
        rmdir($config->cacheFile);
        file_put_contents($config->cacheFile, $cache);
        $compiler->compile();
        self::assertSame('Second edition', (new TextCatalog(new TextCatalogLoader('/unused'), $config->cacheFile, true))->text('shelf.title'));
    }

    public function test_aliased_output_paths_are_rejected_before_any_replacement(): void {
        $this->writeSource('shelf.neon', "shelf:\n    title: Read\n");
        $this->compiler($this->config())->compile();
        $before = $this->artifactBytes();
        $config = $this->config(potFile: $this->temporaryDirectory . '/cache/../cache/catalog.php');
        try {
            $this->compiler($config)->compile();
            self::fail('Outputs resolving to the same file must not overwrite one another.');
        } catch (RuntimeException) {
            self::assertSame($before, $this->artifactBytes());
        }
    }

    private function writeSource(string $relativePath, string $contents): void {
        $path = $this->temporaryDirectory . '/texts/' . $relativePath;
        if ( ! is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, $contents);
    }

    private function loader(): TextCatalogLoader {
        return new TextCatalogLoader($this->temporaryDirectory . '/texts');
    }

    /** @param non-empty-list<non-empty-string> $locales */
    private function config(array $locales = ['en_US'], string $sourceLocale = 'en_US', bool $frontend = true, ?string $potFile = null): CatalogConfig {
        return new CatalogConfig(
            sourceDirectory: $this->temporaryDirectory . '/texts',
            cacheFile: $this->temporaryDirectory . '/cache/catalog.php',
            languageDirectory: $this->temporaryDirectory . '/languages',
            sourceRoot: $this->temporaryDirectory,
            domain: 'library',
            locales: $locales,
            sourceLocale: $sourceLocale,
            frontendDirectory: $frontend ? $this->temporaryDirectory . '/frontend' : null,
            potFile: $potFile,
        );
    }

    private function compiler(CatalogConfig $config): TextCatalogCompiler {
        return new TextCatalogCompiler($this->loader(), $config);
    }

    private function localePath(string $locale, string $extension): string {
        return $this->temporaryDirectory . '/languages/' . $locale . '/LC_MESSAGES/library.' . $extension;
    }

    private function writePo(string $locale, Translations $translations): void {
        $path = $this->localePath($locale, 'po');
        if ( ! is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        (new PoGenerator())->generateFile($translations, $path);
    }

    /** @return array<string, string> */
    private function artifactBytes(): array {
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->temporaryDirectory, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile() && in_array($file->getExtension(), ['php', 'pot', 'po', 'mo', 'ts', 'json'], true)) {
                $files[$file->getPathname()] = (string) file_get_contents($file->getPathname());
            }
        }
        ksort($files, SORT_STRING);
        return $files;
    }
}
