<?php

declare(strict_types=1);

namespace Lsr\TextCatalog\Laravel;

use Illuminate\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Illuminate\View\Compilers\BladeCompiler;
use InvalidArgumentException;
use Lsr\TextCatalog\CatalogConfig;
use Lsr\TextCatalog\Console\CompileTextCatalogCommand;
use Lsr\TextCatalog\TextCatalog;
use Lsr\TextCatalog\TextCatalogCompiler;
use Lsr\TextCatalog\TextCatalogLoader;
use Lsr\TextCatalog\Translation\TextTranslator;
use Lsr\Translations\GettextEnvironment;
use Lsr\Translations\MessageTranslator;

/**
 * Laravel adapter: catalog services, the `texts:cache:compile` Artisan command and, with lsr/translations,
 * the TextTranslator plus `@langText` / `@langHtmlText` Blade directives.
 */
final class TextCatalogServiceProvider extends ServiceProvider
{
    public function register(): void {
        $this->mergeConfigFrom(self::configFile(), 'text-catalog');

        $this->app->singleton(CatalogConfig::class, static function (Application $app): CatalogConfig {
            $config = self::config($app);
            return new CatalogConfig(
                sourceDirectory: $config->string('text-catalog.source_directory'),
                cacheFile: $config->string('text-catalog.cache_file'),
                languageDirectory: $config->string('text-catalog.language_directory'),
                sourceRoot: $config->string('text-catalog.source_root'),
                domain: $config->string('text-catalog.domain'),
                locales: $config->array('text-catalog.locales'),
                sourceLocale: $config->string('text-catalog.source_locale'),
                frontendDirectory: self::nullableString($config, 'text-catalog.frontend_directory'),
                potFile: self::nullableString($config, 'text-catalog.pot_file'),
            );
        });
        $this->app->singleton(
            TextCatalogLoader::class,
            static fn (Application $app): TextCatalogLoader => new TextCatalogLoader(
                $app->make(CatalogConfig::class)->sourceDirectory,
            ),
        );
        $this->app->singleton(
            TextCatalog::class,
            static fn (Application $app): TextCatalog => new TextCatalog(
                $app->make(TextCatalogLoader::class),
                $app->make(CatalogConfig::class)->cacheFile,
                self::config($app)->boolean('text-catalog.use_compiled_cache'),
            ),
        );
        $this->app->singleton(
            TextCatalogCompiler::class,
            static fn (Application $app): TextCatalogCompiler => new TextCatalogCompiler(
                $app->make(TextCatalogLoader::class),
                $app->make(CatalogConfig::class),
            ),
        );

        if ( ! interface_exists(MessageTranslator::class)) {
            return;
        }
        $this->app->singleton(
            TextTranslator::class,
            static fn (Application $app): TextTranslator => new TextTranslator(
                $app->make(TextCatalog::class),
                $app->make(MessageTranslator::class),
                $app->make(CatalogConfig::class)->domain,
                self::sanitizer($app),
            ),
        );
        // Bind the catalog domain to its language directory whenever lsr/translations applies a locale.
        $this->app->extend(
            GettextEnvironment::class,
            static function (GettextEnvironment $environment, Application $app): GettextEnvironment {
                $config = $app->make(CatalogConfig::class);
                return $environment->withDomain($config->domain, $config->languageDirectory);
            },
        );
    }

    public function boot(): void {
        $this->publishes(
            [self::configFile() => $this->app->configPath('text-catalog.php')],
            'text-catalog-config',
        );
        if ($this->app->runningInConsole()) {
            $this->commands([CompileTextCatalogCommand::class]);
        }
        if ( ! interface_exists(MessageTranslator::class)) {
            return;
        }
        $this->callAfterResolving('blade.compiler', static function (BladeCompiler $blade): void {
            $translator = 'app(\\' . TextTranslator::class . '::class)';
            $blade->directive(
                'langText',
                static fn (string $expression): string => '<?php echo e(' . $translator . '->langText(' . $expression . ')); ?>',
            );
            // Sanitized by the configured application sanitizer, therefore echoed raw.
            $blade->directive(
                'langHtmlText',
                static fn (string $expression): string => '<?php echo ' . $translator . '->langHtmlText(' . $expression . '); ?>',
            );
        });
    }

    /** @return null|callable(string): string */
    private static function sanitizer(Application $app): ?callable {
        $binding = self::nullableString(self::config($app), 'text-catalog.sanitizer');
        if ($binding === null) {
            return null;
        }
        $sanitizer = $app->make($binding);
        if ( ! is_callable($sanitizer)) {
            throw new InvalidArgumentException('text-catalog.sanitizer must resolve to a callable.');
        }
        return static function (string $html) use ($sanitizer): string {
            $sanitized = $sanitizer($html);
            if ( ! is_string($sanitized)) {
                throw new InvalidArgumentException('The text-catalog HTML sanitizer must return a string.');
            }
            return $sanitized;
        };
    }

    private static function nullableString(Repository $config, string $key): ?string {
        return $config->get($key) === null ? null : $config->string($key);
    }

    private static function config(Application $app): Repository {
        return $app->make(Repository::class);
    }

    private static function configFile(): string {
        return dirname(__DIR__, 2) . '/config/text-catalog.php';
    }
}
