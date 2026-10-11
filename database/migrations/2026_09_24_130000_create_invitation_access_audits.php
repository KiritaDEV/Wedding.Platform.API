<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invitation_access_audits', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('invitation_id');
            $table->string('event_type', 40);
            $table->string('actor_type', 24);
            $table->ulid('actor_user_id')->nullable();
            $table->string('actor_name_snapshot')->nullable();
            $table->json('metadata')->nullable();
            $table->dateTime('occurred_at');

            $table->foreign('invitation_id', 'iaa_invitation_fk')->references('id')->on('invitations')->cascadeOnDelete();
            $table->foreign('actor_user_id', 'iaa_actor_user_fk')->references('id')->on('users')->nullOnDelete();
            $table->index(['invitation_id', 'occurred_at', 'id'], 'iaa_invitation_time_idx');
        });

        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE invitation_access_audits ADD CONSTRAINT iaa_event_type_check CHECK (event_type IN ('trusted_access_claimed','access_transfer_requested','access_transfer_approved','access_transfer_rejected','access_transfer_expired','access_transfer_invalidated','trusted_access_reset','private_link_rotated'))");
            DB::statement("ALTER TABLE invitation_access_audits ADD CONSTRAINT iaa_actor_type_check CHECK (actor_type IN ('management_user','private_browser','system'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('invitation_access_audits');
    }
};
