<?php

namespace Database\Factories;

use App\Models\Event;
use App\Models\Website;
use App\Website\Capabilities\WebsiteCapabilityResolver;
use App\Website\WebsiteSchema;
use App\Website\WebsiteTemplateRegistry;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Website> */
class WebsiteFactory extends Factory
{
    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
            'name' => Website::DEFAULT_NAME,
            'template_key' => WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1,
            'design_settings' => app(WebsiteCapabilityResolver::class)
                ->designSettingsForStorage(WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1, []),
            'schema_version' => WebsiteSchema::CURRENT_SCHEMA_VERSION,
        ];
    }
}
