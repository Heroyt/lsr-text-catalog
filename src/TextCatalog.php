<?php

declare(strict_types=1);

namespace Lsr\TextCatalog;

use RuntimeException;

final class TextCatalog
{
    private ?TextCatalogDefinition $definition = null;

    public function __construct(
        private readonly TextCatalogLoader $loader,
        private readonly string $cacheFile,
        private readonly bool $useCompiledCache,
    ) {
    }

    public function text(string $key): string {
        return $this->definition()->text($key);
    }

    public function has(string $key): bool {
        return $this->definition()->has($key);
    }

    public function isHtml(string $key): bool {
        return $this->definition()->isHtml($key);
    }

    /** @return array<non-empty-string, non-empty-string> */
    public function all(): array {
        return $this->definition()->texts;
    }

    private function definition(): TextCatalogDefinition {
        if ($this->definition !== null) {
            return $this->definition;
        }

        if ($this->useCompiledCache && is_file($this->cacheFile)) {
            return $this->definition = $this->loadCompiledCache();
        }

        return $this->definition = $this->loader->load();
    }

    private function loadCompiledCache(): TextCatalogDefinition {
        $cache = (static fn (string $file): mixed => require $file)($this->cacheFile);
        if (
            ! is_array($cache)
            || ! is_array($cache['texts'] ?? null)
            || ! is_array($cache['htmlKeys'] ?? null)
            || ! is_array($cache['plurals'] ?? null)
        ) {
            throw new RuntimeException('Compiled text catalog cache has an invalid structure: ' . $this->cacheFile);
        }

        foreach ($cache['texts'] as $key => $text) {
            if ( ! is_string($key) || $key === '' || ! is_string($text) || $text === '') {
                throw new RuntimeException('Compiled text catalog cache contains an invalid entry: ' . $this->cacheFile);
            }
        }

        foreach ($cache['htmlKeys'] as $key => $flag) {
            if ( ! is_string($key) || $key === '' || $flag !== true || ! isset($cache['texts'][$key])) {
                throw new RuntimeException('Compiled text catalog cache contains an invalid HTML key: ' . $this->cacheFile);
            }
        }

        foreach ($cache['plurals'] as $context => $forms) {
            if ( ! is_string($context) || $context === '' || ! is_array($forms)) {
                throw new RuntimeException('Compiled text catalog cache contains an invalid plural entry: ' . $this->cacheFile);
            }
            $one = $forms['one'] ?? null;
            $plural = $forms['plural'] ?? null;
            if (
                ! is_string($one)
                || $one === ''
                || ! isset($cache['texts'][$one])
                || $one !== $context . '.one'
                || ! is_string($plural)
                || $plural === ''
                || ! isset($cache['texts'][$plural])
                || $plural !== $context . '.plural'
            ) {
                throw new RuntimeException('Compiled text catalog cache contains an invalid plural entry: ' . $this->cacheFile);
            }
        }

        /** @var array<non-empty-string, non-empty-string> $texts */
        $texts = $cache['texts'];
        /** @var array<non-empty-string, true> $htmlKeys */
        $htmlKeys = $cache['htmlKeys'];
        /** @var array<non-empty-string, array{one: non-empty-string, plural: non-empty-string}> $plurals */
        $plurals = $cache['plurals'];

        return new TextCatalogDefinition($texts, $htmlKeys, $plurals, []);
    }
}
