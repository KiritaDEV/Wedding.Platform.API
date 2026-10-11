<?php

namespace App\Website\Capabilities;

final readonly class ContextDefaultsIntent
{
    public function __construct(
        public ?string $headingFontId = null,
        public ?string $bodyFontId = null,
        public ?string $headingColorId = null,
        public ?string $bodyColorId = null,
        public ?string $accentColorId = null,
    ) {}

    /** @return array<string, string> */
    public function toArray(): array
    {
        return array_filter(get_object_vars($this), fn (?string $value): bool => $value !== null);
    }

    /** @param array<string, string> $values */
    public static function fromArray(array $values): self
    {
        return new self(
            headingFontId: $values['headingFontId'] ?? null,
            bodyFontId: $values['bodyFontId'] ?? null,
            headingColorId: $values['headingColorId'] ?? null,
            bodyColorId: $values['bodyColorId'] ?? null,
            accentColorId: $values['accentColorId'] ?? null,
        );
    }
}
