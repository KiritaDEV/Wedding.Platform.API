<?php

namespace Tests\Unit;

use App\Website\Elements\WebsiteElementValidator;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ScheduleAppearanceTest extends TestCase
{
    private function element(array $appearance): array
    {
        return ['id' => 'placement', 'type' => 'schedule', 'editorName' => 'Schedule', 'contentId' => 'content', 'appearance' => $appearance];
    }

    public function test_sparse_appearance_and_existing_spacing_animation_are_preserved(): void
    {
        foreach ([['layout' => 'list', 'list' => ['timePosition' => 'above', 'separation' => 'none']],
            ['layout' => 'timeline', 'rowSpacing' => 'l', 'timeline' => ['arrangement' => 'alternating', 'marker' => 'number', 'lineWidth' => 2, 'markerColorId' => 'marker', 'lineColorId' => 'line']],
            ['layout' => 'cards', 'cards' => ['columns' => 'grid', 'gap' => 's', 'padding' => 'l', 'backgroundColorId' => 'background', 'borderColorId' => 'border', 'borderWidth' => 0, 'radius' => 'm', 'alignment' => 'center']],
            ['layout' => 'cards', 'outerSpacing' => ['top' => 'm']],
        ] as $appearance) {
            $element = $this->element($appearance);
            $this->assertSame($element, app(WebsiteElementValidator::class)->validate($element));
        }
        $plain = ['id' => 'placement', 'type' => 'schedule', 'editorName' => 'Schedule', 'contentId' => 'content'];
        $this->assertSame($plain, app(WebsiteElementValidator::class)->validate($plain));
        $this->assertSame($plain, app(WebsiteElementValidator::class)->validate($this->element(['timeline' => []])));
    }

    #[DataProvider('invalidAppearance')]
    public function test_strict_appearance_rejects_malformed_values(array $appearance): void
    {
        $this->expectException(ValidationException::class);
        app(WebsiteElementValidator::class)->validate($this->element($appearance));
    }

    public static function invalidAppearance(): array
    {
        return array_map(fn ($value) => [$value], [
            ['layout' => 'unknown'], ['layout' => null], ['rowSpacing' => 'huge'], ['timeline' => ['arrangement' => 'horizontal']],
            ['timeline' => ['marker' => 'image']], ['timeline' => ['lineWidth' => '1']], ['timeline' => ['lineWidth' => 4]],
            ['timeline' => ['lineWidth' => true]], ['cards' => ['borderWidth' => -1]], ['cards' => ['columns' => 'three']],
            ['cards' => ['backgroundColorId' => '']], ['cards' => ['radius' => 'huge']], ['cards' => ['alignment' => 'justify']],
            ['list' => ['timePosition' => 'right']], ['list' => ['separation' => 'dots']], ['cards' => ['unknown' => true]],
            ['typography' => ['unknown' => []]], ['cards' => ['padding' => 24]],
        ]);
    }
}
