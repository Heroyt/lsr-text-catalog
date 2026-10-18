<?php

declare(strict_types=1);

namespace Tests\Support;

/** Test-only HTML policy: keeps `<em>` and removes every other tag from the final output. */
final class StripTagsSanitizer
{
    public function __invoke(string $html): string {
        return strip_tags($html, '<em>');
    }
}
