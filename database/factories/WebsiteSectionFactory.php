<?php

namespace Database\Factories;

use App\Models\Website;
use App\Models\WebsiteSection;
use App\Website\WebsiteSectionAppearance;
use App\Website\WebsiteSectionRegistry;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<WebsiteSection> */
class WebsiteSectionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'website_id' => Website::factory(),
            'type' => fake()->randomElement(array_keys(app(WebsiteSectionRegistry::class)->all())),
            'sort_order' => fake()->numberBetween(1, 20),
            'is_enabled' => true,
            'content' => [],
            'appearance' => WebsiteSectionAppearance::DEFAULT,
        ];
    }

    public function forType(string $type): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => $type,
            'content' => app(WebsiteSectionRegistry::class)->get($type)?->defaultContent ?? [],
            'appearance' => in_array($type, ['hero', 'blank'], true)
                ? ['shared' => WebsiteSectionAppearance::DEFAULT]
                : WebsiteSectionAppearance::DEFAULT,
        ]);
    }

    public function disabled(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_enabled' => false,
        ]);
    }
}
