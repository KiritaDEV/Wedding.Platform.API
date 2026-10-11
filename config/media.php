<?php

return [
    'disk' => env('MEDIA_DISK', 'local'),
    'max_upload_kilobytes' => 20 * 1024,
    'max_pixels' => 50_000_000,
    'thumbnail_max_edge' => 800,
    'web_max_edge' => 1920,
    'webp_quality' => 82,
];
