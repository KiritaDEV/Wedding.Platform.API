<?php

namespace App\Website\Capabilities;

final readonly class AppearanceControlCapability
{
    /**
     * @param  list<array{key: string, displayName: string}>  $options
     * @param  array<string, ViewportControlCapability>  $viewports
     */
    public function __construct(
        public string $id,
        public AppearanceControlType $type,
        public AppearanceControlScope $scope,
        public mixed $default,
        public array $options = [],
        public ?float $minimum = null,
        public ?float $maximum = null,
        public ?float $step = null,
        public array $viewports = [],
    ) {}

    public function forViewport(string $viewport): ?self
    {
        if ($this->scope === AppearanceControlScope::Shared) {
            return $this;
        }

        $narrowing = $this->viewports[$viewport] ?? null;
        if ($narrowing === null) {
            return null;
        }

        return new self(
            id: $this->id,
            type: $this->type,
            scope: $this->scope,
            default: $narrowing->default,
            options: $narrowing->options,
            minimum: $this->minimum,
            maximum: $this->maximum,
            step: $this->step,
        );
    }
}
