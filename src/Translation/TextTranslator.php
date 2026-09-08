<?php

declare(strict_types=1);

namespace Lsr\TextCatalog\Translation;

use Closure;
use InvalidArgumentException;
use LogicException;
use Lsr\TextCatalog\TextCatalog;

/** Native gettext adapter; the application owns locale/domain binding and HTML policy. */
final readonly class TextTranslator
{
    private ?Closure $sanitizeHtml;

    /** @param null|callable(string): string $sanitizeHtml */
    public function __construct(
        private TextCatalog $catalog,
        private string $domain,
        ?callable $sanitizeHtml = null,
    ) {
        if ( ! extension_loaded('gettext')) {
            throw new LogicException('Native text translation requires ext-gettext.');
        }
        if ($domain === '') {
            throw new InvalidArgumentException('The gettext domain must not be empty.');
        }
        $this->sanitizeHtml = $sanitizeHtml === null ? null : Closure::fromCallable($sanitizeHtml);
    }

    /** @param array<int|string, bool|float|int|string|null> $format */
    public function langText(string $key, ?string $pluralKey = null, int $num = 1, array $format = []): string {
        $context = $key;
        $plural = null;
        if ($pluralKey !== null) {
            if ( ! str_ends_with($key, '.one')) {
                throw new InvalidArgumentException('A plural singular key must end in ".one".');
            }
            $context = substr($key, 0, -4);
            if ($pluralKey !== $context . '.plural') {
                throw new InvalidArgumentException('Plural keys must be sibling ".one" and ".plural" entries.');
            }
            $plural = $this->catalog->text($pluralKey);
        }

        $message = $context . "\004" . $this->catalog->text($key);
        $translated = $plural === null
            ? dgettext($this->domain, $message)
            : dngettext($this->domain, $message, $plural, $num);
        $separator = strpos($translated, "\004");
        if ($separator !== false) {
            $translated = substr($translated, $separator + 1);
        }
        if ($format === []) {
            return $translated;
        }
        $numericKeys = array_filter(array_keys($format), 'is_int');
        if ($numericKeys !== [] && count($numericKeys) !== count($format)) {
            throw new InvalidArgumentException('Format parameters cannot mix numeric and named keys.');
        }
        if ($numericKeys !== []) {
            return sprintf($translated, ...array_values($format));
        }

        return preg_replace_callback(
            '/%\{((?:.|\n)+?)\}/',
            static function (array $matches) use ($format): string {
                $name = trim($matches[1]);
                return array_key_exists($name, $format) ? (string) $format[$name] : $matches[0];
            },
            $translated,
        ) ?? $translated;
    }

    /** @param array<int|string, bool|float|int|string|null> $format */
    public function langHtmlText(string $key, array $format = []): string {
        if ( ! $this->catalog->isHtml($key)) {
            throw new InvalidArgumentException('Text catalog key "' . $key . '" is not declared as HTML.');
        }
        if ($this->sanitizeHtml === null) {
            throw new LogicException('HTML translation requires an application-provided sanitizer.');
        }
        return ($this->sanitizeHtml)($this->langText($key, format: $format));
    }
}
