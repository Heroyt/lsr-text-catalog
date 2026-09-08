<?php

declare(strict_types=1);

namespace Lsr\TextCatalog;

use InvalidArgumentException;

final readonly class CatalogConfig
{
    public string $potFile;
    /** @var non-empty-list<non-empty-string> */
    public array $locales;

    /** @param array<array-key, mixed> $locales */
    public function __construct(
        public string $sourceDirectory,
        public string $cacheFile,
        public string $languageDirectory,
        public string $sourceRoot,
        public string $domain,
        array $locales,
        public string $sourceLocale,
        public ?string $frontendDirectory = null,
        ?string $potFile = null,
    ) {
        foreach ([
            'sourceDirectory' => $sourceDirectory,
            'cacheFile' => $cacheFile,
            'languageDirectory' => $languageDirectory,
            'sourceRoot' => $sourceRoot,
            'frontendDirectory' => $frontendDirectory,
            'potFile' => $potFile,
        ] as $name => $path) {
            if ($path !== null && (trim($path) === '' || str_contains($path, "\0") || str_contains($path, '://'))) {
                throw new InvalidArgumentException($name . ' must be a non-empty local filesystem path.');
            }
        }
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]*$/D', $domain) !== 1) {
            throw new InvalidArgumentException('The gettext domain must be a safe ASCII filename component.');
        }
        self::validateLocales($locales, $sourceLocale);
        $this->locales = $locales;

        $this->potFile = $potFile ?? rtrim($languageDirectory, '/\\') . '/' . $domain . '.pot';
    }

    /**
     * @param array<array-key, mixed> $locales
     * @phpstan-assert non-empty-list<non-empty-string> $locales
     */
    private static function validateLocales(array $locales, string $sourceLocale): void {
        if ($locales === [] || ! array_is_list($locales)) {
            throw new InvalidArgumentException('Compiled locales must be a non-empty list.');
        }
        foreach ($locales as $locale) {
            if ( ! is_string($locale) || preg_match('/^[A-Za-z][A-Za-z0-9_-]*(?:\.[A-Za-z0-9_-]+)?(?:@[A-Za-z0-9_-]+)?$/D', $locale) !== 1) {
                throw new InvalidArgumentException('Each locale must be a safe ASCII locale identifier.');
            }
        }
        if (count(array_unique($locales)) !== count($locales)) {
            throw new InvalidArgumentException('Compiled locales must not contain duplicates.');
        }
        if ( ! in_array($sourceLocale, $locales, true)) {
            throw new InvalidArgumentException('The source locale must be included in compiled locales.');
        }
    }
}
