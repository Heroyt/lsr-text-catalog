<?php

declare(strict_types=1);

namespace Tests\Integration;

use FilesystemIterator;
use Gettext\Generator\MoGenerator;
use Gettext\Translation;
use Gettext\Translations;
use LogicException;
use Lsr\TextCatalog\Console\CompileTextCatalogCommand;
use Lsr\TextCatalog\Di\TextCatalogExtension;
use Lsr\TextCatalog\TextCatalog;
use Lsr\TextCatalog\TextCatalogLoader;
use Lsr\TextCatalog\Translation\TextTranslator;
use Lsr\Translations\GettextTranslator;
use Lsr\Translations\MessageTranslator;
use Nette\DI\Compiler;
use Nette\DI\Container;
use Nette\DI\ContainerLoader;
use Nette\DI\Definitions\Statement;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\Support\StripTagsSanitizer;

final class CatalogIntegrationTest extends TestCase
{
    private string $directory;

    protected function setUp(): void {
        $this->directory = sys_get_temp_dir() . '/lsr-catalog-integration-' . bin2hex(random_bytes(8));
        mkdir($this->directory . '/copy', 0777, true);
        file_put_contents($this->directory . '/copy/example.neon', <<<'NEON'
example:
    title: 'Welcome %{name}'
    other: 'Welcome %{name}'
    count:
        one: '%{count} item'
        plural: '%{count} items'
    rich:
        html: '<strong>%{name}</strong>'
NEON);
    }

    protected function tearDown(): void {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->directory);
    }

    public function test_di_registers_optional_command_without_catalog_copy_keys(): void {
        $container = $this->container(true);
        $command = $container->getByType(CompileTextCatalogCommand::class);
        self::assertInstanceOf(CompileTextCatalogCommand::class, $command);
        $tester = new CommandTester($command);
        self::assertSame(0, $tester->execute([]));
        $catalog = $container->getByType(TextCatalog::class);
        self::assertInstanceOf(TextCatalog::class, $catalog);
        self::assertSame('Welcome %{name}', $catalog->text('example.title'));
        self::assertFileExists($this->directory . '/cache/catalog.php');
        self::assertFileExists($this->directory . '/languages/en_US/LC_MESSAGES/external.mo');

        $cache = file_get_contents($this->directory . '/cache/catalog.php');
        file_put_contents($this->directory . '/copy/example.neon', "wrongRoot: invalid\n");
        self::assertSame(1, $tester->execute([]));
        self::assertSame($cache, file_get_contents($this->directory . '/cache/catalog.php'));
    }

    public function test_di_does_not_register_a_command_unless_enabled(): void {
        $container = $this->container(false);
        self::assertNull($container->getByType(CompileTextCatalogCommand::class, false));
        $catalog = $container->getByType(TextCatalog::class);
        self::assertInstanceOf(TextCatalog::class, $catalog);
        self::assertSame('%{count} item', $catalog->text('example.count.one'));
        self::assertFileDoesNotExist($this->directory . '/cache/catalog.php');
    }

    public function test_di_translator_uses_a_native_translator_unless_the_application_provides_one(): void {
        $options = ['translator' => true, 'sanitizer' => '@sanitizer'];
        $services = ['sanitizer' => StripTagsSanitizer::class];
        $container = $this->container(false, $options, $services);
        self::assertInstanceOf(TextTranslator::class, $container->getByType(TextTranslator::class));
        self::assertTrue($container->hasService('texts.messageTranslator'));

        $application = $this->container(false, $options, $services + ['appTranslator' => new Statement(GettextTranslator::class, ['application'])]);
        self::assertInstanceOf(TextTranslator::class, $application->getByType(TextTranslator::class));
        self::assertFalse($application->hasService('texts.messageTranslator'));
        self::assertSame($application->getService('appTranslator'), $application->getByType(MessageTranslator::class));
    }

    public function test_native_context_plural_interpolation_and_sanitizer_order(): void {
        if ( ! extension_loaded('gettext')) {
            self::markTestSkipped('Native adapter requires ext-gettext.');
        }
        $previousLocale = setlocale(LC_MESSAGES, '0');
        if (setlocale(LC_MESSAGES, 'cs_CZ.UTF-8', 'cs_CZ') === false) {
            self::markTestSkipped('Native gettext test requires a Czech locale.');
        }
        // GNU gettext on macOS also consults the process environment.
        $previousEnvironment = [];
        foreach (['LANG', 'LC_ALL', 'LANGUAGE'] as $name) {
            $previousEnvironment[$name] = getenv($name);
            putenv($name . '=cs_CZ.UTF-8');
        }
        try {
            $domain = 'catalog_' . bin2hex(random_bytes(6));
            $translations = Translations::create($domain, 'cs_CZ');
            $translations->getHeaders()->setPluralForm(3, '(n == 1) ? 0 : ((n >= 2 && n <= 4) ? 1 : 2)');
            $title = Translation::create('example.title', 'Welcome %{name}');
            $title->translate('Ahoj %{name}');
            $translations->add($title);
            $other = Translation::create('example.other', 'Welcome %{name}');
            $other->translate('Vítej %{name}');
            $translations->add($other);
            $count = Translation::create('example.count', '%{count} item')->setPlural('%{count} items');
            $count->translate('%{count} položka');
            $count->translatePlural('%{count} položky', '%{count} položek');
            $translations->add($count);
            $html = Translation::create('example.rich', '<strong>%{name}</strong>');
            $html->translate('<em>%{name}</em>');
            $translations->add($html);
            $moDirectory = $this->directory . '/languages/cs_CZ/LC_MESSAGES';
            mkdir($moDirectory, 0777, true);
            file_put_contents($moDirectory . '/' . $domain . '.mo', (new MoGenerator())->includeHeaders()->generateString($translations));
            bindtextdomain($domain, $this->directory . '/languages');
            bind_textdomain_codeset($domain, 'UTF-8');
            $catalog = new TextCatalog(new TextCatalogLoader($this->directory . '/copy'), $this->directory . '/cache.php', false);
            $translator = new TextTranslator($catalog, new GettextTranslator($domain), $domain, new StripTagsSanitizer());
            self::assertSame('Ahoj Ada', $translator->langText('example.title', format: ['name' => 'Ada']));
            self::assertSame('Vítej Ada', $translator->langText('example.other', format: ['name' => 'Ada']));
            self::assertSame('3 položky', $translator->langText('example.count.one', 'example.count.plural', 3, ['count' => 3]));
            self::assertSame('8 položek', $translator->langText('example.count.one', 'example.count.plural', 8, ['count' => 8]));
            self::assertSame('<em>Ada</em>', $translator->langHtmlText('example.rich', ['name' => '<b>Ada</b>']));
            $this->expectException(LogicException::class);
            (new TextTranslator($catalog, new GettextTranslator($domain), $domain))->langHtmlText('example.rich');
        } finally {
            foreach ($previousEnvironment as $name => $value) {
                putenv($value === false ? $name : $name . '=' . $value);
            }
            if ($previousLocale !== false) {
                setlocale(LC_MESSAGES, $previousLocale);
            }
        }
    }

    /**
     * @param array<string, mixed> $options
     * @param array<string, string|Statement> $services
     */
    private function container(bool $command, array $options = [], array $services = []): Container {
        $directory = $this->directory;
        $config = [
            'texts' => [
                'sourceDirectory' => $directory . '/copy',
                'sourceRoot' => $directory,
                'cacheFile' => $directory . '/cache/catalog.php',
                'languageDirectory' => $directory . '/languages',
                'domain' => 'external',
                'locales' => ['en_US'],
                'sourceLocale' => 'en_US',
                'command' => $command,
                ...$options,
            ],
            'services' => $services,
        ];
        $loader = new ContainerLoader($directory . '/di', true);
        $class = $loader->load(static function (Compiler $compiler) use ($config): null {
            $compiler->addExtension('texts', new TextCatalogExtension());
            $compiler->addConfig($config);
            return null;
        }, serialize($config));
        return new $class();
    }
}
