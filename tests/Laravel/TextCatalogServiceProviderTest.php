<?php

declare(strict_types=1);

namespace Tests\Laravel;

use FilesystemIterator;
use Gettext\Generator\MoGenerator;
use Gettext\Translation;
use Gettext\Translations;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Blade;
use Lsr\TextCatalog\Laravel\TextCatalogServiceProvider;
use Lsr\TextCatalog\Translation\TextTranslator;
use Lsr\Translations\Laravel\TranslationsServiceProvider;
use Orchestra\Testbench\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\Support\StripTagsSanitizer;

final class TextCatalogServiceProviderTest extends TestCase
{
    private string $directory;
    private string $domain;
    /** @var array<string, false|string> */
    private array $environment = [];
    private string|false $locale = false;

    protected function setUp(): void {
        $this->directory = sys_get_temp_dir() . '/lsr-catalog-laravel-' . bin2hex(random_bytes(8));
        $this->domain = 'catalog_' . bin2hex(random_bytes(4));
        mkdir($this->directory . '/copy', 0777, true);
        file_put_contents($this->directory . '/copy/example.neon', <<<'NEON'
example:
    title: 'Welcome %{name}'
    count:
        one: '%{count} item'
        plural: '%{count} items'
    rich:
        html: '<strong>%{name}</strong>'
NEON);
        foreach (['LANGUAGE', 'LANG', 'LC_ALL'] as $name) {
            $this->environment[$name] = getenv($name);
        }
        $this->locale = setlocale(LC_ALL, '0');
        parent::setUp();
    }

    protected function tearDown(): void {
        parent::tearDown();
        foreach ($this->environment as $name => $value) {
            putenv($value === false ? $name : $name . '=' . $value);
        }
        if ($this->locale !== false) {
            setlocale(LC_ALL, $this->locale);
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $file) {
            assert($file instanceof SplFileInfo);
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->directory);
    }

    protected function getPackageProviders($app): array {
        return [TranslationsServiceProvider::class, TextCatalogServiceProvider::class];
    }

    protected function defineEnvironment($app): void {
        $config = $app->make(Repository::class);
        $config->set('app.locale', 'en');
        $config->set('lsr-translations.directory', $this->directory . '/app-languages');
        $config->set('lsr-translations.supported_languages', ['en_US', 'cs_CZ']);
        $config->set('text-catalog.source_directory', $this->directory . '/copy');
        $config->set('text-catalog.cache_file', $this->directory . '/cache/catalog.php');
        $config->set('text-catalog.language_directory', $this->directory . '/languages');
        $config->set('text-catalog.source_root', $this->directory);
        $config->set('text-catalog.domain', $this->domain);
        $config->set('text-catalog.sanitizer', StripTagsSanitizer::class);
    }

    public function test_artisan_compiles_the_configured_catalog_and_fails_without_publishing(): void {
        $kernel = $this->application()->make(Kernel::class);

        self::assertSame(0, $kernel->call('texts:cache:compile'));
        self::assertStringContainsString('Compiled 4 text catalog entries.', $kernel->output());
        self::assertFileExists($this->directory . '/cache/catalog.php');
        self::assertFileExists($this->directory . '/languages/en_US/LC_MESSAGES/' . $this->domain . '.mo');

        $cache = file_get_contents($this->directory . '/cache/catalog.php');
        file_put_contents($this->directory . '/copy/example.neon', "wrongRoot: invalid\n");
        self::assertSame(1, $kernel->call('texts:cache:compile'));
        self::assertSame($cache, file_get_contents($this->directory . '/cache/catalog.php'));
    }

    public function test_catalog_domain_follows_the_laravel_locale_in_php_and_blade(): void {
        if ( ! extension_loaded('gettext') || setlocale(LC_MESSAGES, 'cs_CZ.UTF-8', 'cs_CZ') === false) {
            self::markTestSkipped('Native catalog translation requires ext-gettext and a Czech locale.');
        }
        $this->writeCzechCatalog();
        $app = $this->application();
        $translator = $app->make(TextTranslator::class);

        self::assertSame('Welcome Ada', $translator->langText('example.title', format: ['name' => 'Ada']));
        $app->setLocale('cs');
        self::assertSame('Ahoj Ada', $translator->langText('example.title', format: ['name' => 'Ada']));
        self::assertSame('3 položky', $translator->langText('example.count.one', 'example.count.plural', 3, ['count' => 3]));
        self::assertSame(
            'Ahoj &lt;i&gt;Ada&lt;/i&gt; | <em>Ada</em>',
            Blade::render(
                "@langText('example.title', format: ['name' => \$name]) | @langHtmlText('example.rich', ['name' => \$name])",
                ['name' => '<i>Ada</i>'],
            ),
        );
    }

    private function writeCzechCatalog(): void {
        $translations = Translations::create($this->domain, 'cs_CZ');
        $translations->getHeaders()->setPluralForm(3, '(n == 1) ? 0 : ((n >= 2 && n <= 4) ? 1 : 2)');
        $title = Translation::create('example.title', 'Welcome %{name}');
        $title->translate('Ahoj %{name}');
        $translations->add($title);
        $count = Translation::create('example.count', '%{count} item')->setPlural('%{count} items');
        $count->translate('%{count} položka');
        $count->translatePlural('%{count} položky', '%{count} položek');
        $translations->add($count);
        $rich = Translation::create('example.rich', '<strong>%{name}</strong>');
        $rich->translate('<em>%{name}</em>');
        $translations->add($rich);
        $directory = $this->directory . '/languages/cs_CZ/LC_MESSAGES';
        mkdir($directory, 0777, true);
        file_put_contents(
            $directory . '/' . $this->domain . '.mo',
            new MoGenerator()->includeHeaders()->generateString($translations),
        );
    }

    private function application(): Application {
        self::assertNotNull($this->app);
        return $this->app;
    }
}
