<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('invitation_access_transfer_requests')) {
            Schema::create('invitation_access_transfer_requests', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->ulid('invitation_id');
                $table->ulid('requested_credential_id');
                $table->string('status', 16);
                $table->enum('current_slot', ['active'])->nullable();
                $table->string('requested_browser_family')->nullable();
                $table->string('requested_platform')->nullable();
                // DATETIME avoids legacy MySQL TIMESTAMP implicit-default rules while
                // these application-authored instants continue to be stored in UTC.
                $table->dateTime('requested_at');
                $table->dateTime('expires_at');
                $table->dateTime('approved_at')->nullable();
                $table->dateTime('rejected_at')->nullable();
                $table->dateTime('invalidated_at')->nullable();
                $table->timestamps();
            });
        }

        $foreignColumns = collect(Schema::getForeignKeys('invitation_access_transfer_requests'))
            ->pluck('columns')->flatten()->all();
        Schema::table('invitation_access_transfer_requests', function (Blueprint $table) use ($foreignColumns): void {
            if (! in_array('invitation_id', $foreignColumns, true)) {
                $table->foreign('invitation_id', 'iatr_invitation_fk')->references('id')->on('invitations')->cascadeOnDelete();
            }
            if (! in_array('requested_credential_id', $foreignColumns, true)) {
                $table->foreign('requested_credential_id', 'iatr_requested_credential_fk')
                    ->references('id')->on('invitation_browser_credentials')->cascadeOnDelete();
            }
        });

        $indexNames = collect(Schema::getIndexes('invitation_access_transfer_requests'))->pluck('name')->all();
        Schema::table('invitation_access_transfer_requests', function (Blueprint $table) use ($indexNames): void {
            if (! in_array('iatr_current_unique', $indexNames, true)) {
                $table->unique(['invitation_id', 'current_slot'], 'iatr_current_unique');
            }
            if (! in_array('iatr_lookup_idx', $indexNames, true)) {
                $table->index(['invitation_id', 'status', 'expires_at'], 'iatr_lookup_idx');
            }
        });

        if (DB::connection()->getDriverName() === 'mysql' && ! $this->mysqlCheckConstraintExists('iatr_current_slot_check')) {
            DB::statement("ALTER TABLE invitation_access_transfer_requests ADD CONSTRAINT iatr_current_slot_check CHECK (current_slot IS NULL OR current_slot = 'active')");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('invitation_access_transfer_requests');
    }

    private function mysqlCheckConstraintExists(string $name): bool
    {
        return DB::table('information_schema.table_constraints')
            ->where('constraint_schema', DB::connection()->getDatabaseName())
            ->where('table_name', 'invitation_access_transfer_requests')
            ->where('constraint_name', $name)
            ->where('constraint_type', 'CHECK')
            ->exists();
    }
};
