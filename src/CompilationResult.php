<?php

declare(strict_types=1);

namespace Lsr\TextCatalog;

final readonly class CompilationResult
{
    /**
     * @param list<string> $staleCleanupFailures Stale `texts/*.ts` paths that could not be removed after publication
     *     (best-effort cleanup; the new generation is published regardless).
     */
    public function __construct(
        public TextCatalogDefinition $definition,
        public ?string $generation,
        public ?string $manifestFile,
        public array $staleCleanupFailures = [],
    ) {
    }
}
