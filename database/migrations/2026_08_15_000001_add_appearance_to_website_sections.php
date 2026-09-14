<?php

use App\Website\WebsiteSectionAppearance;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('website_sections', function (Blueprint $table) {
            $table->json('appearance')->nullable()->after('content');
        });

        DB::table('website_sections')->whereNull('appearance')->update([
            'appearance' => json_encode(WebsiteSectionAppearance::DEFAULT, JSON_THROW_ON_ERROR),
        ]);
        DB::table('website_sections')->whereIn('type', ['hero', 'blank'])->update([
            'appearance' => json_encode(['shared' => WebsiteSectionAppearance::DEFAULT], JSON_THROW_ON_ERROR),
        ]);

        if (DB::table('website_sections')->whereNull('appearance')->exists()) {
            throw new RuntimeException('Every Website Section must have appearance settings before the column becomes non-null.');
        }

        Schema::table('website_sections', function (Blueprint $table) {
            $table->json('appearance')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('website_sections', function (Blueprint $table) {
            $table->dropColumn('appearance');
        });
    }
};
