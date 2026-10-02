<?php

namespace Tests\Unit;

use App\Website\WebsiteAnimation;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WebsiteAnimationTest extends TestCase
{
    #[DataProvider('validAnimations')]
    public function test_it_accepts_the_canonical_contract(array $value): void
    {
        $this->assertSame($value, WebsiteAnimation::normalize($value, 'animation', true));
    }

    public static function validAnimations(): array
    {
        $cases = [];
        foreach (['none', 'fade', 'fade-up', 'fade-down', 'scale-in'] as $type) {
            $cases["type {$type}"] = [['entrance' => ['type' => $type]]];
        }
        foreach (['fast', 'normal', 'slow'] as $speed) {
            $cases["speed {$speed}"] = [['entrance' => ['type' => 'fade', 'speed' => $speed]]];
        }
        foreach (['none', 'short', 'medium', 'long'] as $delay) {
            $cases["delay {$delay}"] = [['entrance' => ['type' => 'fade', 'delay' => $delay]]];
        }

        return $cases;
    }

    #[DataProvider('invalidAnimations')]
    public function test_it_rejects_noncanonical_animation_json(array $value): void
    {
        $this->expectException(ValidationException::class);
        WebsiteAnimation::normalize($value, 'animation');
    }

    public static function invalidAnimations(): array
    {
        return [
            'unknown root key' => [['entrance' => ['type' => 'fade'], 'responsive' => []]],
            'unknown entrance key' => [['entrance' => ['type' => 'fade', 'duration' => 300]]],
            'invalid type' => [['entrance' => ['type' => 'slide']]],
            'invalid speed' => [['entrance' => ['type' => 'fade', 'speed' => 'custom']]],
            'numeric delay' => [['entrance' => ['type' => 'fade', 'delay' => 100]]],
            'arbitrary transform' => [['entrance' => ['type' => 'fade', 'transform' => 'scale(2)']]],
        ];
    }

    public function test_it_normalizes_empty_and_none_base_values_sparsely(): void
    {
        $this->assertNull(WebsiteAnimation::normalize([], 'animation'));
        $this->assertNull(WebsiteAnimation::normalize(['entrance' => []], 'animation'));
        $this->assertNull(WebsiteAnimation::normalize(['entrance' => ['speed' => 'fast']], 'animation'));
        $this->assertNull(WebsiteAnimation::normalize(['entrance' => ['type' => 'none', 'delay' => 'long']], 'animation'));
        $this->assertSame(['entrance' => ['type' => 'none']], WebsiteAnimation::normalize(['entrance' => ['type' => 'none', 'delay' => 'long']], 'animation', true));
    }
}
