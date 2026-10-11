<?php

use App\Actions\Invitations\SynchronizeBuiltinWeddingRoles;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $binaryCollation = DB::connection()->getDriverName() === 'mysql' ? 'utf8mb4_bin' : 'BINARY';

        Schema::create('invitations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('event_id')->constrained()->cascadeOnDelete();
            $table->string('custom_name')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();

            $table->unique(['event_id', 'id']);
            $table->index(['event_id', 'status', 'created_at']);
        });

        Schema::create('guests', function (Blueprint $table) use ($binaryCollation) {
            $table->ulid('id')->primary();
            $table->ulid('event_id');
            $table->ulid('invitation_id');
            $table->string('first_name');
            $table->string('last_name')->nullable();
            $table->string('normalized_first_name')->collation($binaryCollation);
            $table->string('normalized_last_name')->default('')->collation($binaryCollation);
            $table->string('relationship')->default('guest_other');
            $table->string('side')->default('unspecified');
            $table->timestamps();

            $table->unique(['event_id', 'id']);
            $table->unique(['event_id', 'normalized_first_name', 'normalized_last_name'], 'guests_event_normalized_name_unique');
            $table->index(['invitation_id', 'created_at']);
            $table->foreign(['event_id', 'invitation_id'])
                ->references(['event_id', 'id'])->on('invitations')->cascadeOnDelete();
        });

        Schema::create('wedding_roles', function (Blueprint $table) use ($binaryCollation) {
            $table->ulid('id')->primary();
            $table->foreignUlid('event_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('scope_key', 26);
            $table->string('key')->nullable()->unique();
            $table->string('name');
            $table->string('normalized_name')->collation($binaryCollation);
            $table->boolean('is_builtin')->default(false);
            $table->timestamps();

            $table->unique(['scope_key', 'normalized_name'], 'wedding_roles_scope_name_unique');
            $table->index(['event_id', 'name']);
        });

        app(SynchronizeBuiltinWeddingRoles::class)->handle();

        Schema::create('guest_wedding_role', function (Blueprint $table) {
            $table->ulid('guest_id');
            $table->ulid('wedding_role_id');
            $table->timestamps();

            $table->primary(['guest_id', 'wedding_role_id']);
            $table->foreign('guest_id')->references('id')->on('guests')->cascadeOnDelete();
            $table->foreign('wedding_role_id')->references('id')->on('wedding_roles')->cascadeOnDelete();
            $table->index(['wedding_role_id', 'guest_id']);
        });

        $this->createRoleAssignmentTriggers();
    }

    public function down(): void
    {
        $this->dropRoleAssignmentTriggers();
        Schema::dropIfExists('guest_wedding_role');
        Schema::dropIfExists('wedding_roles');
        Schema::dropIfExists('guests');
        Schema::dropIfExists('invitations');
    }

    private function createRoleAssignmentTriggers(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER guest_wedding_role_same_event_insert
                BEFORE INSERT ON guest_wedding_role
                WHEN EXISTS (
                    SELECT 1 FROM guests g, wedding_roles r
                    WHERE g.id = NEW.guest_id AND r.id = NEW.wedding_role_id
                      AND r.event_id IS NOT NULL AND r.event_id <> g.event_id
                )
                BEGIN
                    SELECT RAISE(ABORT, 'Custom Wedding Role must belong to the Guest Event');
                END
                SQL);
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER guest_wedding_role_same_event_update
                BEFORE UPDATE ON guest_wedding_role
                WHEN EXISTS (
                    SELECT 1 FROM guests g, wedding_roles r
                    WHERE g.id = NEW.guest_id AND r.id = NEW.wedding_role_id
                      AND r.event_id IS NOT NULL AND r.event_id <> g.event_id
                )
                BEGIN
                    SELECT RAISE(ABORT, 'Custom Wedding Role must belong to the Guest Event');
                END
                SQL);

            return;
        }

        if (DB::connection()->getDriverName() === 'mysql') {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER guest_wedding_role_same_event_insert
                BEFORE INSERT ON guest_wedding_role FOR EACH ROW
                BEGIN
                    IF EXISTS (
                        SELECT 1 FROM guests g JOIN wedding_roles r
                          ON r.id = NEW.wedding_role_id
                        WHERE g.id = NEW.guest_id
                          AND r.event_id IS NOT NULL
                          AND r.event_id <> g.event_id
                    ) THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Custom Wedding Role must belong to the Guest Event';
                    END IF;
                END
                SQL);
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER guest_wedding_role_same_event_update
                BEFORE UPDATE ON guest_wedding_role FOR EACH ROW
                BEGIN
                    IF EXISTS (
                        SELECT 1 FROM guests g JOIN wedding_roles r
                          ON r.id = NEW.wedding_role_id
                        WHERE g.id = NEW.guest_id
                          AND r.event_id IS NOT NULL
                          AND r.event_id <> g.event_id
                    ) THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Custom Wedding Role must belong to the Guest Event';
                    END IF;
                END
                SQL);
        }
    }

    private function dropRoleAssignmentTriggers(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS guest_wedding_role_same_event_insert');
        DB::unprepared('DROP TRIGGER IF EXISTS guest_wedding_role_same_event_update');
    }
};
