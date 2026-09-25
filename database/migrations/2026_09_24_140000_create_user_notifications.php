<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_notifications', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('recipient_user_id');
            $table->ulid('event_id');
            $table->ulid('invitation_id');
            $table->string('type', 40);
            $table->string('source_type', 32);
            $table->ulid('source_id');
            $table->string('event_name_snapshot');
            $table->string('invitation_name_snapshot');
            $table->json('metadata')->nullable();
            $table->dateTime('occurred_at');
            $table->dateTime('read_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->foreign('recipient_user_id', 'un_recipient_fk')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('event_id', 'un_event_fk')->references('id')->on('events')->cascadeOnDelete();
            $table->foreign('invitation_id', 'un_invitation_fk')->references('id')->on('invitations')->cascadeOnDelete();
            $table->unique(['recipient_user_id', 'source_type', 'source_id'], 'un_recipient_source_unique');
            $table->index(['recipient_user_id', 'read_at'], 'un_recipient_read_idx');
            $table->index(['recipient_user_id', 'occurred_at', 'id'], 'un_recipient_time_idx');
        });

        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE user_notifications ADD CONSTRAINT un_type_check CHECK (type IN ('guest_rsvp_received','guest_rsvp_updated','access_transfer_requested'))");
            DB::statement("ALTER TABLE user_notifications ADD CONSTRAINT un_source_check CHECK (source_type IN ('rsvp_submission','access_transfer_request'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('user_notifications');
    }
};
