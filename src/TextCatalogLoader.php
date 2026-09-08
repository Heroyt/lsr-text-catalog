<?php

declare(strict_types=1);

namespace Lsr\TextCatalog;

use DOMDocument;
use FilesystemIterator;
use InvalidArgumentException;
use Nette\Neon\Neon;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

final readonly class TextCatalogLoader
{
    private const array ENTITY_REPLACEMENTS = [
        '&nbsp;' => "\u{00A0}",
        '&#160;' => "\u{00A0}",
        '&#xA0;' => "\u{00A0}",
        '&#xa0;' => "\u{00A0}",
    ];

    private const array HTML_ATTRIBUTES = [
        'a' => ['href', 'rel', 'target', 'title'],
        'abbr' => ['title'],
        'br' => [],
        'code' => [],
        'em' => [],
        'i' => [],
        'small' => [],
        'span' => [],
        'strong' => [],
        'sub' => [],
        'sup' => [],
    ];

    public function __construct(private string $directory) {
    }

    public function load(): TextCatalogDefinition {
        if ( ! is_dir($this->directory)) {
            throw new RuntimeException('text catalog catalog directory does not exist: ' . $this->directory);
        }

        $directory = rtrim($this->directory, '/\\') . DIRECTORY_SEPARATOR;
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'neon') {
                $files[] = $file->getPathname();
            }
        }
        if ($files === []) {
            throw new RuntimeException('text catalog catalog contains no NEON files: ' . $this->directory);
        }
        sort($files, SORT_STRING);

        $texts = [];
        $htmlKeys = [];
        $plurals = [];
        $sourceFiles = [];

        foreach ($files as $file) {
            if ($file === '') {
                throw new RuntimeException('text catalog catalog contains an empty file path.');
            }
            $decoded = Neon::decodeFile($file);
            if ( ! is_array($decoded) || array_is_list($decoded)) {
                throw new InvalidArgumentException($file . ' must contain a keyed NEON mapping.');
            }

            $expectedRoot = pathinfo($file, PATHINFO_FILENAME);
            if ($expectedRoot === '' || preg_match('/^[a-z][A-Za-z0-9]*$/D', $expectedRoot) !== 1) {
                throw new InvalidArgumentException($file . ' must use a camelCase catalog filename.');
            }
            $segments = explode(DIRECTORY_SEPARATOR, substr($file, strlen($directory), -5));
            foreach ($segments as $segment) {
                if (preg_match('/^[a-z][A-Za-z0-9]*$/D', $segment) !== 1) {
                    throw new InvalidArgumentException($file . ' must use camelCase catalog directory names.');
                }
            }
            if (array_keys($decoded) !== [$expectedRoot]) {
                throw new InvalidArgumentException($file . ' must contain exactly the root namespace "' . $expectedRoot . '".');
            }

            $this->flatten(
                $decoded[$expectedRoot],
                implode('.', $segments),
                $file,
                $texts,
                $htmlKeys,
                $plurals,
                $sourceFiles,
            );
        }

        ksort($texts, SORT_STRING);
        ksort($htmlKeys, SORT_STRING);
        ksort($plurals, SORT_STRING);
        ksort($sourceFiles, SORT_STRING);

        return new TextCatalogDefinition($texts, $htmlKeys, $plurals, $sourceFiles);
    }

    /**
     * @param non-empty-string $key
     * @param non-empty-string $file
     * @param array<non-empty-string, non-empty-string> $texts
     * @param array<non-empty-string, true> $htmlKeys
     * @param array<non-empty-string, array{one: non-empty-string, plural: non-empty-string}> $plurals
     * @param array<non-empty-string, non-empty-string> $sourceFiles
     */
    private function flatten(
        mixed $node,
        string $key,
        string $file,
        array &$texts,
        array &$htmlKeys,
        array &$plurals,
        array &$sourceFiles,
    ): void {
        if (is_string($node)) {
            $this->addText($key, $node, $file, false, $texts, $htmlKeys, $sourceFiles);
            return;
        }
        if ( ! is_array($node) || array_is_list($node)) {
            throw new InvalidArgumentException($file . ': "' . $key . '" must be a string or keyed mapping.');
        }

        $keys = array_keys($node);
        sort($keys, SORT_STRING);
        if ($keys === ['html']) {
            if ( ! is_string($node['html'])) {
                throw new InvalidArgumentException($file . ': HTML entry "' . $key . '" must contain a string.');
            }
            $this->addText($key, $node['html'], $file, true, $texts, $htmlKeys, $sourceFiles);
            return;
        }
        if ($keys === ['one', 'plural']) {
            if ( ! is_string($node['one']) || ! is_string($node['plural'])) {
                throw new InvalidArgumentException($file . ': plural entry "' . $key . '" must contain string one and plural forms.');
            }

            $oneKey = $key . '.one';
            $pluralKey = $key . '.plural';
            $this->addText($oneKey, $node['one'], $file, false, $texts, $htmlKeys, $sourceFiles);
            $this->addText($pluralKey, $node['plural'], $file, false, $texts, $htmlKeys, $sourceFiles);
            $this->assertMatchingPlaceholders($key, $texts[$oneKey], $texts[$pluralKey], $file);
            $plurals[$key] = ['one' => $oneKey, 'plural' => $pluralKey];
            return;
        }
        if (in_array('html', $keys, true) || in_array('one', $keys, true) || in_array('plural', $keys, true)) {
            throw new InvalidArgumentException($file . ': "' . $key . '" uses an incomplete reserved entry shape.');
        }

        foreach ($node as $segment => $value) {
            if ( ! is_string($segment) || preg_match('/^[a-z][A-Za-z0-9]*$/D', $segment) !== 1) {
                throw new InvalidArgumentException($file . ': invalid key segment under "' . $key . '".');
            }
            $this->flatten($value, $key . '.' . $segment, $file, $texts, $htmlKeys, $plurals, $sourceFiles);
        }
    }

    /**
     * @param non-empty-string $key
     * @param non-empty-string $file
     * @param array<non-empty-string, non-empty-string> $texts
     * @param array<non-empty-string, true> $htmlKeys
     * @param array<non-empty-string, non-empty-string> $sourceFiles
     */
    private function addText(
        string $key,
        string $text,
        string $file,
        bool $html,
        array &$texts,
        array &$htmlKeys,
        array &$sourceFiles,
    ): void {
        if (isset($texts[$key])) {
            throw new InvalidArgumentException('Duplicate text catalog key "' . $key . '" in ' . $file . ' and ' . $sourceFiles[$key] . '.');
        }

        $text = strtr($text, self::ENTITY_REPLACEMENTS);
        if ($text === '') {
            throw new InvalidArgumentException($file . ': text catalog entry "' . $key . '" cannot be empty.');
        }
        if ($html) {
            $this->validateHtml($key, $text, $file);
            $htmlKeys[$key] = true;
        }

        $texts[$key] = $text;
        $sourceFiles[$key] = $file;
    }

    private function assertMatchingPlaceholders(string $key, string $one, string $plural, string $file): void {
        $onePlaceholders = $this->placeholders($one);
        $pluralPlaceholders = $this->placeholders($plural);
        if ($onePlaceholders !== $pluralPlaceholders) {
            throw new InvalidArgumentException(
                $file . ': plural entry "' . $key . '" must use identical placeholders in one and plural forms.',
            );
        }
    }

    /** @return list<string> */
    private function placeholders(string $text): array {
        preg_match_all('/%\{\s*([A-Za-z_][A-Za-z0-9_.]*)\s*\}/', $text, $matches);
        $placeholders = array_values(array_unique($matches[1]));
        sort($placeholders, SORT_STRING);
        return $placeholders;
    }

    private function validateHtml(string $key, string $html, string $file): void {
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        $document = new DOMDocument('1.0', 'UTF-8');
        $loaded = $document->loadHTML(
            '<?xml encoding="UTF-8"><div data-catalog-root>' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD,
        );
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ( ! $loaded || $errors !== []) {
            throw new InvalidArgumentException($file . ': HTML entry "' . $key . '" contains malformed HTML.');
        }


        foreach ($document->getElementsByTagName('*') as $element) {
            if ($element === $document->documentElement) {
                continue;
            }

            $tag = strtolower($element->tagName);
            if ( ! array_key_exists($tag, self::HTML_ATTRIBUTES)) {
                throw new InvalidArgumentException($file . ': HTML entry "' . $key . '" contains disallowed <' . $tag . '> markup.');
            }

            foreach (iterator_to_array($element->attributes) as $attribute) {
                $name = strtolower($attribute->name);
                if ( ! in_array($name, self::HTML_ATTRIBUTES[$tag], true)) {
                    throw new InvalidArgumentException(
                        $file . ': HTML entry "' . $key . '" contains disallowed attribute "' . $name . '".',
                    );
                }
                if ($tag === 'a' && $name === 'href') {
                    $this->validateHref($key, $attribute->value, $file);
                }
            }
        }
    }

    private function validateHref(string $key, string $href, string $file): void {
        if (preg_match('/^%\{\s*[A-Za-z_][A-Za-z0-9_.]*\s*\}$/D', $href) === 1) {
            return;
        }
        if (str_starts_with($href, '//')) {
            throw new InvalidArgumentException($file . ': HTML entry "' . $key . '" contains an unsafe link target.');
        }
        if (str_starts_with($href, '/') || str_starts_with($href, '#') || str_starts_with($href, '?')) {
            return;
        }

        $scheme = strtolower((string) parse_url($href, PHP_URL_SCHEME));
        if (in_array($scheme, ['https', 'mailto', 'tel'], true)) {
            return;
        }

        throw new InvalidArgumentException($file . ': HTML entry "' . $key . '" contains an unsafe link target.');
    }
}
