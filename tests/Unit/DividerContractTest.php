<?php

namespace Tests\Unit;

use App\Website\Elements\WebsiteElementValidator;
use App\Website\WebsiteSectionContentValidator;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DividerContractTest extends TestCase
{
    public static function schemaCases(): array
    {
        $fixtures = json_decode(file_get_contents(__DIR__.'/../Fixtures/divider-contract.json'), true, flags: JSON_THROW_ON_ERROR);

        return array_column(array_map(fn ($case) => ['name' => $case['name'], 'args' => [$case['element'], $case['valid']]], $fixtures['schemaCases']), 'args', 'name');
    }

    #[DataProvider('schemaCases')]
    public function test_mirrored_web_schema_fixture(array $element, bool $valid): void
    {
        if (! $valid) {
            $this->expectException(ValidationException::class);
        }
        $this->assertSame($element, app(WebsiteElementValidator::class)->validate($element));
    }

    public static function templateCases(): array
    {
        $fixtures = json_decode(file_get_contents(__DIR__.'/../Fixtures/divider-contract.json'), true, flags: JSON_THROW_ON_ERROR);
        $cases = [];
        foreach ([0, 1, 2] as $depth) {
            foreach ($fixtures['templateCases'] as $case) {
                $cases[$depth.' '.$case['name']] = [$case['element'], $case['templateKey'], $case['valid'], $depth];
            }
        }

        return $cases;
    }

    #[DataProvider('templateCases')]
    public function test_mirrored_web_template_fixture(array $element, string $templateKey, bool $valid, int $depth): void
    {
        for ($index = 0; $index < $depth; $index++) {
            $element = ['id' => 'group-'.$index, 'type' => 'compositionGroup', 'editorName' => 'Group '.($index + 1), 'children' => [$element]];
        }
        $content = ['semantic' => [], 'compositions' => ['shared' => ['childFlow' => ['elements' => [$element], 'order' => [['kind' => 'element', 'id' => $element['id']]]]]]];
        if (! $valid) {
            $this->expectException(ValidationException::class);
        }
        $this->assertSame($content, app(WebsiteSectionContentValidator::class)->validate('blank', $content, templateKey: $templateKey));
    }
}
