<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Migration-only backfill for pre-live local Events created before timezone became mandatory.
        DB::table('events')->whereNull('time_zone')->update(['time_zone' => 'UTC']);

        Schema::table('events', function (Blueprint $table) {
            $table->string('time_zone', 64)->nullable(false)->change();
            $table->boolean('rsvp_is_open')->default(false)->after('time_zone');
            $table->date('rsvp_deadline')->nullable()->after('rsvp_is_open');
        });

        Schema::table('guests', function (Blueprint $table) {
            $table->string('status')->default('active')->after('side');
            $table->index(['invitation_id', 'status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('guests', function (Blueprint $table) {
            $table->dropIndex(['invitation_id', 'status', 'created_at']);
            $table->dropColumn('status');
        });
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn(['rsvp_is_open', 'rsvp_deadline']);
            $table->string('time_zone', 64)->nullable()->change();
        });
    }
};
