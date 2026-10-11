<?php

namespace App\Website\Publishing;

/** Additional readiness rules (including future Schedule rules) are container-tagged by this interface. */
interface WebsitePublicationRule
{
    /** @return list<array<string, mixed>> Structured diagnostics; an empty list means ready. */
    public function diagnostics(PublishedWebsiteSnapshot $snapshot): array;
}
