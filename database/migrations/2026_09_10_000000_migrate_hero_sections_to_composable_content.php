<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('website_sections')
            ->where('type', 'hero')
            ->orderBy('id')
            ->chunkById(250, function ($sections): void {
                foreach ($sections as $section) {
                    $content = $this->decode($section->content);
                    $appearance = $this->decode($section->appearance);
                    $backgroundMedia = $content['backgroundMedia'] ?? $content['media'] ?? null;

                    if (isset($content['compositions']['shared']['childFlow'])) {
                        // Already uses the canonical device-neutral composition envelope.
                    } elseif (! isset($content['childFlow']) || ! is_array($content['childFlow'])) {
                        $content = $this->composableContent($content);
                    } else {
                        unset($content['headline'], $content['subheadline'], $content['media']);
                        $content = ['semantic' => [], 'compositions' => ['shared' => ['childFlow' => $content['childFlow']]]];
                    }
                    $content['semantic'] = [];

                    $appearance = ['shared' => $this->heroAppearance($appearance['shared'] ?? $appearance, $backgroundMedia)];

                    DB::table('website_sections')->where('id', $section->id)->update([
                        'content' => json_encode($content, JSON_THROW_ON_ERROR),
                        'appearance' => json_encode($appearance, JSON_THROW_ON_ERROR),
                        'updated_at' => now(),
                    ]);
                }
            }, 'id');
    }

    public function down(): void
    {
        // The retired fixed Hero contract cannot represent an arbitrary child flow.
    }

    /** @param array<string, mixed> $legacy */
    private function composableContent(array $legacy): array
    {
        $headlineId = (string) Str::ulid();
        $dateId = (string) Str::ulid();
        $supportingId = (string) Str::ulid();
        $headline = is_string($legacy['headline'] ?? null) ? $legacy['headline'] : '';
        $supporting = is_string($legacy['subheadline'] ?? null) ? $legacy['subheadline'] : '';

        $content = ['semantic' => [], 'compositions' => ['shared' => [
            'childFlow' => [
                'elements' => [
                    ['id' => $headlineId, 'type' => 'text', 'editorName' => 'Text 1', 'document' => ['type' => 'doc', 'children' => [['type' => 'paragraph', 'children' => [['text' => $headline]]]]], 'appearance' => ['fontSize' => 'xl', 'fontWeight' => 700, 'alignment' => 'center']],
                    ['id' => $dateId, 'type' => 'date', 'editorName' => 'Date 1', 'appearance' => ['alignment' => 'center']],
                    ['id' => $supportingId, 'type' => 'text', 'editorName' => 'Text 2', 'document' => ['type' => 'doc', 'children' => [['type' => 'paragraph', 'children' => [['text' => $supporting]]]]], 'appearance' => ['alignment' => 'center']],
                ],
                'order' => array_map(fn (string $id): array => ['kind' => 'element', 'id' => $id], [$headlineId, $dateId, $supportingId]),
            ],
        ]]];

        return $content;
    }

    /** @param array<string, mixed> $appearance @return array<string, mixed> */
    private function heroAppearance(array $appearance, mixed $backgroundMedia): array
    {
        $presentation = $appearance['presentation'] ?? null;
        $allowed = ['headingAlignment', 'bodyAlignment', 'backgroundTreatment', 'emphasis', 'decorativeAppearance', 'designDefaults', 'backgroundImageOpacity', 'height', 'backgroundMedia'];
        $appearance = array_intersect_key($appearance, array_flip($allowed));

        if ($presentation === 'immersive') {
            $appearance['height'] = ['unit' => 'svh', 'value' => 100];
        }
        if (is_array($backgroundMedia)) {
            $appearance['backgroundMedia'] = $backgroundMedia;
        }

        return $appearance;
    }

    /** @return array<string, mixed> */
    private function decode(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || $value === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }
};
