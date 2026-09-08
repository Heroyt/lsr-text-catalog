<?php

declare(strict_types=1);

namespace Tests\Core;

use InvalidArgumentException;
use Lsr\TextCatalog\CatalogConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CatalogConfigTest extends TestCase
{
    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidConfigurations(): iterable {
        yield 'empty source directory' => [['sourceDirectory' => '']];
        yield 'blank cache path' => [['cacheFile' => ' ']];
        yield 'embedded null' => [['sourceRoot' => "source\0root"]];
        yield 'stream output' => [['potFile' => 'php://output']];
        yield 'empty frontend directory' => [['frontendDirectory' => '']];
        yield 'domain traversal' => [['domain' => '../messages']];
        yield 'domain path separator' => [['domain' => 'nested/messages']];
        yield 'non-ASCII domain' => [['domain' => 'zprávy']];
        yield 'empty locales' => [['locales' => []]];
        yield 'keyed locales' => [['locales' => ['source' => 'en_US']]];
        yield 'duplicate locales' => [['locales' => ['en_US', 'en_US']]];
        yield 'locale traversal' => [['locales' => ['en_US', '../cs_CZ']]];
        yield 'non-string locale' => [['locales' => ['en_US', 42]]];
        yield 'absent source locale' => [['sourceLocale' => 'cs_CZ']];
    }

    /** @param array<string, mixed> $overrides */
    #[DataProvider('invalidConfigurations')]
    public function test_configuration_rejects_unsafe_paths_and_ambiguous_locale_identity(array $overrides): void {
        $arguments = array_replace([
            'sourceDirectory' => 'texts',
            'cacheFile' => 'cache/catalog.php',
            'languageDirectory' => 'languages',
            'sourceRoot' => '.',
            'domain' => 'library',
            'locales' => ['en_US'],
            'sourceLocale' => 'en_US',
        ], $overrides);
        $this->expectException(InvalidArgumentException::class);
        new CatalogConfig(...$arguments);
    }
}
