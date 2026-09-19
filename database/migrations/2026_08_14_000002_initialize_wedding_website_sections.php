<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $definitions = [
            'hero' => [10, ['semantic' => [], 'compositions' => ['shared' => ['childFlow' => ['elements' => [], 'order' => []]]]]],
            'gallery' => [70, ['semantic' => ['items' => []], 'compositions' => ['shared' => ['childFlow' => ['elements' => [], 'order' => [['kind' => 'specialized', 'key' => 'content']]]]]]],
            'rsvp' => [90, ['semantic' => ['heading' => '', 'description' => '', 'buttonLabel' => '']]],
        ];

        DB::table('websites')
            ->join('events', 'events.id', '=', 'websites.event_id')
            ->where('events.type', 'wedding')
            ->select('websites.id')
            ->orderBy('websites.id')
            ->chunk(500, function ($websites) use ($definitions): void {
                $websiteIds = $websites->pluck('id')->all();
                $existing = DB::table('website_sections')
                    ->whereIn('website_id', $websiteIds)
                    ->get(['website_id', 'type'])
                    ->groupBy('website_id');
                $timestamp = now();
                $rows = [];

                foreach ($websiteIds as $websiteId) {
                    $existingTypes = $existing->get($websiteId, collect())->pluck('type')->all();

                    foreach ($definitions as $type => [$order, $content]) {
                        if (in_array($type, $existingTypes, true)) {
                            continue;
                        }

                        $rows[] = [
                            'id' => (string) Str::ulid(),
                            'website_id' => $websiteId,
                            'type' => $type,
                            'sort_order' => $order,
                            'is_enabled' => true,
                            'content' => json_encode($content, JSON_THROW_ON_ERROR),
                            'created_at' => $timestamp,
                            'updated_at' => $timestamp,
                        ];
                    }
                }

                if ($rows !== []) {
                    DB::table('website_sections')->insert($rows);
                }
            });

        Schema::table('website_sections', function (Blueprint $table) {
            $table->unique(['website_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::table('website_sections', function (Blueprint $table) {
            $table->dropUnique(['website_id', 'type']);
        });
    }
};
