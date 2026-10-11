<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('websites', function (Blueprint $table): void {
            $table->char('draft_revision', 26)->default('00000000000000000000000000');
        });
    }

    public function down(): void
    {
        Schema::table('websites', fn (Blueprint $table) => $table->dropColumn('draft_revision'));
    }
};
