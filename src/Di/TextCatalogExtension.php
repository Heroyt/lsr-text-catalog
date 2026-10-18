<?php

declare(strict_types=1);

namespace Lsr\TextCatalog\Di;

use LogicException;
use Lsr\TextCatalog\CatalogConfig;
use Lsr\TextCatalog\Console\CompileTextCatalogCommand;
use Lsr\TextCatalog\TextCatalog;
use Lsr\TextCatalog\TextCatalogCompiler;
use Lsr\TextCatalog\TextCatalogLoader;
use Lsr\TextCatalog\Translation\TextTranslator;
use Lsr\Translations\GettextTranslator;
use Lsr\Translations\MessageTranslator;
use Nette\DI\CompilerExtension;
use Nette\DI\Definitions\Statement;
use Nette\Schema\Expect;
use Nette\Schema\Schema;
use Symfony\Component\Console\Command\Command;

/**
 * @property-read object{
 *     sourceDirectory: string,
 *     cacheFile: string,
 *     languageDirectory: string,
 *     sourceRoot: string,
 *     domain: string,
 *     locales: list<string>,
 *     sourceLocale: string,
 *     frontendDirectory: ?string,
 *     potFile: ?string,
 *     useCompiledCache: bool,
 *     command: bool,
 *     translator: bool,
 *     sanitizer: string|Statement|null
 * } $config
 */
final class TextCatalogExtension extends CompilerExtension
{
    public function getConfigSchema(): Schema {
        return Expect::structure([
            'sourceDirectory' => Expect::string()->required(),
            'cacheFile' => Expect::string()->required(),
            'languageDirectory' => Expect::string()->required(),
            'sourceRoot' => Expect::string()->required(),
            'domain' => Expect::string()->required(),
            'locales' => Expect::listOf('string')->required(),
            'sourceLocale' => Expect::string()->required(),
            'frontendDirectory' => Expect::string()->nullable(),
            'potFile' => Expect::string()->nullable(),
            'useCompiledCache' => Expect::bool(false),
            'command' => Expect::bool(false),
            'translator' => Expect::bool(false),
            'sanitizer' => Expect::anyOf(Expect::string(), Expect::type(Statement::class))->nullable(),
        ]);
    }

    public function loadConfiguration(): void {
        $config = $this->config;
        $builder = $this->getContainerBuilder();
        $builder->addDefinition($this->prefix('config'))
            ->setFactory(CatalogConfig::class, [
                $config->sourceDirectory,
                $config->cacheFile,
                $config->languageDirectory,
                $config->sourceRoot,
                $config->domain,
                $config->locales,
                $config->sourceLocale,
                $config->frontendDirectory,
                $config->potFile,
            ]);
        $builder->addDefinition($this->prefix('loader'))
            ->setFactory(TextCatalogLoader::class, [$config->sourceDirectory]);
        $builder->addDefinition($this->prefix('catalog'))
            ->setFactory(TextCatalog::class, [
                '@' . $this->prefix('loader'),
                $config->cacheFile,
                $config->useCompiledCache,
            ]);
        $builder->addDefinition($this->prefix('compiler'))
            ->setFactory(TextCatalogCompiler::class, [
                '@' . $this->prefix('loader'),
                '@' . $this->prefix('config'),
            ]);

        if ($config->command) {
            if ( ! class_exists(Command::class)) {
                throw new LogicException('Text catalog command registration requires symfony/console.');
            }
            $builder->addDefinition($this->prefix('command'))
                ->setFactory(CompileTextCatalogCommand::class, ['@' . $this->prefix('compiler')]);
        }

        if ($config->translator) {
            if ( ! interface_exists(MessageTranslator::class)) {
                throw new LogicException('Text catalog translator registration requires lsr/translations.');
            }
            $builder->addDefinition($this->prefix('translator'))
                ->setFactory(TextTranslator::class, [
                    'catalog' => '@' . $this->prefix('catalog'),
                    'domain' => $config->domain,
                    'sanitizeHtml' => $config->sanitizer,
                ]);
        }
    }

    public function beforeCompile(): void {
        $builder = $this->getContainerBuilder();
        // Reuse the application's translator; otherwise register a native one for the catalog domain.
        if ($this->config->translator && $builder->findByType(MessageTranslator::class) === []) {
            $builder->addDefinition($this->prefix('messageTranslator'))
                ->setFactory(GettextTranslator::class, [$this->config->domain]);
        }
    }
}
