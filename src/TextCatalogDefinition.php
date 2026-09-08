<?php

declare(strict_types=1);

namespace Lsr\TextCatalog;

use OutOfBoundsException;

final readonly class TextCatalogDefinition
{
    /**
     * @param array<non-empty-string, non-empty-string> $texts
     * @param array<non-empty-string, true> $htmlKeys
     * @param array<non-empty-string, array{one: non-empty-string, plural: non-empty-string}> $plurals
     * @param array<non-empty-string, non-empty-string> $sourceFiles
     */
    public function __construct(
        public array $texts,
        public array $htmlKeys,
        public array $plurals,
        public array $sourceFiles,
    ) {
    }

    public function text(string $key): string {
        return $this->texts[$key] ?? throw new OutOfBoundsException('Unknown text catalog key "' . $key . '".');
    }

    public function has(string $key): bool {
        return isset($this->texts[$key]);
    }

    public function isHtml(string $key): bool {
        return isset($this->htmlKeys[$key]);
    }
}
