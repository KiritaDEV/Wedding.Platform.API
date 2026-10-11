<?php

namespace App\Website\Publishing;

use JsonSerializable;

/**
 * Internal, URL-free presentation candidate. This is not a guest response.
 * Hidden Blocks and disabled Sections are intentionally retained.
 * The JSON boundary detaches all mutable models and nested presentation objects.
 */
final readonly class PublishedWebsiteSnapshot implements JsonSerializable
{
    private string $json;

    /** @internal Construct through PublishedWebsiteSnapshotBuilder. */
    public function __construct(array $project, array $event, array $website, array $mediaAssetIds, array $sharedContentDefinitions = [])
    {
        $this->json = json_encode([
            'project' => $project,
            'event' => $event,
            'website' => $website,
            'mediaAssetIds' => $mediaAssetIds,
            'sharedContentDefinitions' => $sharedContentDefinitions,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public function toJson(): string
    {
        return $this->json;
    }

    /** Returns a detached JSON object tree, preserving empty objects versus lists. */
    public function jsonSerialize(): object
    {
        return json_decode($this->json, false, 512, JSON_THROW_ON_ERROR);
    }
}
