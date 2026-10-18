<?php

declare(strict_types=1);

return [
    // Canonical source-language NEON copy.
    'source_directory' => resource_path('text-catalog'),

    // Compiled PHP lookup cache: trusted build output, deployed with the application.
    'cache_file' => base_path('bootstrap/cache/text-catalog.php'),

    // Gettext POT/PO/MO directory: `<locale>/LC_MESSAGES/<domain>.po`.
    'language_directory' => lang_path('gettext'),

    // Root for portable source references in the POT/PO files.
    'source_root' => base_path(),

    // Dedicated gettext domain of the catalog.
    'domain' => 'catalog',

    // Compiled locales; must include the source locale. Add target locales after preparing their PO files.
    'locales' => ['en_US'],
    'source_locale' => 'en_US',

    // Generated TypeScript artifacts for lsr-text-catalog (npm), or null for PHP-only applications.
    'frontend_directory' => null,

    // POT file path; null uses `<language_directory>/<domain>.pot`.
    'pot_file' => null,

    // Read the compiled PHP cache when it exists instead of loading NEON sources.
    'use_compiled_cache' => true,

    // Container-resolvable callable (class or binding) that sanitizes final HTML output for langHtmlText().
    'sanitizer' => null,
];
