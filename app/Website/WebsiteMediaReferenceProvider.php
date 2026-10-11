<?php

namespace App\Website;

/**
 * Tag providers with this interface in the container to extend the canonical extractor.
 * element() receives resolved shared semantic content with its original placement
 * identity and composition scope. Future Schedule images extend these providers
 * and strict definition validation together; no Activity Image fields exist yet.
 */
interface WebsiteMediaReferenceProvider
{
    /** @return list<array{mediaId: string, reference: array<string, string>}> */
    public function section(string $type, array $content, array $appearance): array;

    /** @return list<array{mediaId: string, reference: array<string, string>}> */
    public function element(array $element, string $compositionScope): array;
}
