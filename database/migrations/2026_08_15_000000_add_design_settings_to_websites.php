<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<string> */
    private const SECTION_COLUMNS = ['id', 'website_id', 'type', 'sort_order', 'is_enabled', 'content', 'created_at', 'updated_at'];

    public function up(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            $table->json('design_settings')->nullable()->after('template_key');
        });

        DB::table('websites')
            ->whereNull('design_settings')
            ->where('template_key', 'classic-filipiniana-v1')
            ->whereExists(function ($query): void {
                $query->selectRaw('1')->from('events')
                    ->whereColumn('events.id', 'websites.event_id')
                    ->where('events.type', 'wedding');
            })
            ->update(['design_settings' => json_encode([
                'colorTheme' => 'terracotta',
                'fontSet' => 'editorial',
                'artStyle' => 'minimal',
            ], JSON_THROW_ON_ERROR)]);

        if (DB::table('websites')->whereNull('design_settings')->exists()) {
            throw new RuntimeException('Every Website must have compatible design settings before the column becomes non-null.');
        }

        $this->alterWebsitesPreservingSections(function (): void {
            Schema::table('websites', function (Blueprint $table) {
                $table->json('design_settings')->nullable(false)->change();
            });
        });
    }

    public function down(): void
    {
        $this->alterWebsitesPreservingSections(function (): void {
            Schema::table('websites', function (Blueprint $table) {
                $table->dropColumn('design_settings');
            });
        });
    }

    private function alterWebsitesPreservingSections(Closure $alter): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $alter();

            return;
        }

        $backup = 'website_sections_w7_backup';
        Schema::dropIfExists($backup);
        Schema::create($backup, function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('website_id');
            $table->string('type');
            $table->unsignedInteger('sort_order');
            $table->boolean('is_enabled');
            $table->json('content');
            $table->timestamps();
        });
        DB::table($backup)->insertUsing(self::SECTION_COLUMNS, DB::table('website_sections')->select(self::SECTION_COLUMNS));
        $alter();
        DB::table('website_sections')->delete();
        DB::table('website_sections')->insertUsing(self::SECTION_COLUMNS, DB::table($backup)->select(self::SECTION_COLUMNS));
        Schema::drop($backup);
    }
};
