<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_assets', function (Blueprint $table) {
            $table->char('content_hash', 64)->nullable()->after('size_bytes');
            $table->unique(['event_id', 'content_hash'], 'media_assets_event_content_hash_unique');
        });
    }

    public function down(): void
    {
        Schema::table('media_assets', function (Blueprint $table) {
            $table->dropUnique('media_assets_event_content_hash_unique');
            $table->dropColumn('content_hash');
        });
    }
};
