<?php

declare(strict_types=1);

namespace Lsr\TextCatalog;

final readonly class CompilationResult
{
    public function __construct(
        public TextCatalogDefinition $definition,
        public ?string $generation,
        public ?string $manifestFile,
    ) {
    }
}
