<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const OLD_UNIQUE = 'website_sections_website_id_type_unique';

    private const SINGLETON_UNIQUE = 'website_sections_website_id_singleton_key_unique';

    public function up(): void
    {
        Schema::table('website_sections', function (Blueprint $table): void {
            $table->dropUnique(self::OLD_UNIQUE);
            $table->string('singleton_key')->nullable()->after('type');
            $table->string('editor_name', 80)->nullable()->after('singleton_key');
        });

        DB::table('website_sections')->where('type', '!=', 'blank')->update(['singleton_key' => DB::raw('type')]);

        Schema::table('website_sections', function (Blueprint $table): void {
            $table->unique(['website_id', 'singleton_key'], self::SINGLETON_UNIQUE);
        });
    }

    public function down(): void
    {
        Schema::table('website_sections', function (Blueprint $table): void {
            $table->dropUnique(self::SINGLETON_UNIQUE);
            $table->dropColumn(['singleton_key', 'editor_name']);
            $table->unique(['website_id', 'type'], self::OLD_UNIQUE);
        });
    }
};
