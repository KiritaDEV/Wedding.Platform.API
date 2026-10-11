<?php

namespace App\Http\Resources;

use App\Website\Capabilities\WebsiteCapabilityResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WebsiteProjectResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $settings = app(WebsiteCapabilityResolver::class)->normalizeDesignSettings(
            $this->template_key,
            $this->design_settings,
        );

        return [
            'id' => $this->id,
            'eventId' => $this->event_id,
            'name' => $this->name,
            'templateKey' => $this->template_key,
            'designSettings' => $settings === null ? $this->design_settings : [
                ...$settings,
                'projectDefaults' => (object) $settings['projectDefaults'],
            ],
            'isPublished' => $this->event?->published_website_id === $this->id,
        ];
    }
}
