<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guests', function (Blueprint $table): void {
            $table->string('rsvp_response', 16)->nullable()->after('status');
            $table->index(['invitation_id', 'status', 'rsvp_response']);
        });

        Schema::create('rsvp_submissions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('event_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('invitation_id')->constrained()->restrictOnDelete();
            $table->string('actor_type', 32);
            $table->foreignUlid('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name_snapshot')->nullable();
            $table->text('note')->nullable();
            $table->timestamp('created_at');
            $table->index(['invitation_id', 'created_at', 'id']);
        });

        Schema::create('rsvp_submission_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('submission_id')->constrained('rsvp_submissions')->cascadeOnDelete();
            $table->foreignUlid('guest_id')->constrained()->restrictOnDelete();
            $table->string('guest_name_snapshot');
            $table->string('rsvp_response', 16)->nullable();
            $table->unsignedInteger('snapshot_order');
            $table->unique(['submission_id', 'guest_id']);
            $table->index(['guest_id', 'submission_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rsvp_submission_items');
        Schema::dropIfExists('rsvp_submissions');
        Schema::table('guests', function (Blueprint $table): void {
            $table->dropIndex(['invitation_id', 'status', 'rsvp_response']);
            $table->dropColumn('rsvp_response');
        });
    }
};
