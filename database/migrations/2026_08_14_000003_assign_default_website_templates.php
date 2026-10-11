<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<string> */
    private const SECTION_COLUMNS = [
        'id',
        'website_id',
        'type',
        'sort_order',
        'is_enabled',
        'content',
        'created_at',
        'updated_at',
    ];

    public function up(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            $table->string('template_key')->nullable()->after('event_id');
        });

        DB::table('websites')
            ->whereNull('template_key')
            ->whereExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('events')
                    ->whereColumn('events.id', 'websites.event_id')
                    ->where('events.type', 'wedding');
            })
            ->update(['template_key' => 'classic-filipiniana-v1']);

        if (DB::table('websites')->whereNull('template_key')->exists()) {
            throw new RuntimeException('Every Website must have a compatible Template before template_key becomes non-null.');
        }

        $this->alterWebsitesPreservingSections(function (): void {
            Schema::table('websites', function (Blueprint $table) {
                $table->string('template_key')->nullable(false)->change();
            });
        });
    }

    public function down(): void
    {
        $this->alterWebsitesPreservingSections(function (): void {
            Schema::table('websites', function (Blueprint $table) {
                $table->dropColumn('template_key');
            });
        });
    }

    private function alterWebsitesPreservingSections(Closure $alter): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $alter();

            return;
        }

        $backupTable = 'website_sections_w3_backup';
        Schema::dropIfExists($backupTable);
        Schema::create($backupTable, function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('website_id');
            $table->string('type');
            $table->unsignedInteger('sort_order');
            $table->boolean('is_enabled');
            $table->json('content');
            $table->timestamps();
        });

        DB::table($backupTable)->insertUsing(
            self::SECTION_COLUMNS,
            DB::table('website_sections')->select(self::SECTION_COLUMNS),
        );

        $alter();

        DB::table('website_sections')->delete();
        DB::table('website_sections')->insertUsing(
            self::SECTION_COLUMNS,
            DB::table($backupTable)->select(self::SECTION_COLUMNS),
        );

        Schema::drop($backupTable);
    }
};
