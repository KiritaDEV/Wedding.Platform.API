<?php

namespace App\Actions\Media;

final readonly class MediaAssetUsage
{
    /** @param list<array<string, mixed>> $references */
    public function __construct(public array $references) {}

    public static function empty(): self
    {
        return new self([]);
    }

    public function isInUse(): bool
    {
        return $this->references !== [];
    }

    /** @return array{isInUse: bool, references: list<array<string, mixed>>} */
    public function toArray(): array
    {
        return [
            'isInUse' => $this->isInUse(),
            'references' => $this->references,
        ];
    }
}
