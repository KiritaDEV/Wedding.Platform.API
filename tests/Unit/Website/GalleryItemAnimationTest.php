<?php

namespace Tests\Unit\Website;

use App\Website\GalleryItemAnimation;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class GalleryItemAnimationTest extends TestCase
{
    public static function validValues(): array
    {
        $values = [];
        foreach (['none', 'fade', 'fade-up', 'fade-down', 'scale-in'] as $type) {
            foreach (['fast', 'normal', 'slow'] as $speed) {
                foreach (['none', 'short', 'medium', 'long'] as $stagger) {
                    $values[] = [$type, $speed, $stagger];
                }
            }
        }

        return $values;
    }

    #[DataProvider('validValues')]
    public function test_it_accepts_the_canonical_contract(string $type, string $speed, string $stagger): void
    {
        $result = GalleryItemAnimation::normalize(['entrance' => compact('type', 'speed', 'stagger')], 'appearance.galleryItemAnimation', true);
        $this->assertSame($type, $result['entrance']['type']);
    }

    public function test_it_removes_defaults_and_preserves_exact_device_none(): void
    {
        $this->assertSame(['entrance' => ['type' => 'fade']], GalleryItemAnimation::normalize(['entrance' => ['type' => 'fade', 'speed' => 'normal', 'stagger' => 'none']], 'path'));
        $this->assertNull(GalleryItemAnimation::normalize(['entrance' => ['type' => 'none']], 'path'));
        $this->assertSame(['entrance' => ['type' => 'none']], GalleryItemAnimation::normalize(['entrance' => ['type' => 'none']], 'path', true));
    }

    public function test_it_rejects_numeric_stagger_and_unknown_keys(): void
    {
        foreach ([['entrance' => ['type' => 'fade', 'stagger' => 60]], ['entrance' => ['type' => 'fade', 'delay' => 'short']]] as $value) {
            try {
                GalleryItemAnimation::normalize($value, 'path');
                $this->fail('Expected validation failure.');
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
