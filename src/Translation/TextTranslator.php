<?php

declare(strict_types=1);

namespace Lsr\TextCatalog\Translation;

use Closure;
use InvalidArgumentException;
use LogicException;
use Lsr\TextCatalog\TextCatalog;
use Lsr\Translations\MessageTranslator;

/**
 * Translates catalog keys through an `lsr/translations` translator. The semantic key is the gettext context
 * (the parent key for plurals); the application owns the locale and the HTML policy.
 */
final readonly class TextTranslator
{
    private ?Closure $sanitizeHtml;

    /** @param null|callable(string): string $sanitizeHtml */
    public function __construct(
        private TextCatalog $catalog,
        private MessageTranslator $translator,
        private string $domain,
        ?callable $sanitizeHtml = null,
    ) {
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

        return $this->translator->translate(
            $this->catalog->text($key),
            plural: $plural,
            num: $num,
            context: $context,
            domain: $this->domain,
            format: $format,
        );
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
